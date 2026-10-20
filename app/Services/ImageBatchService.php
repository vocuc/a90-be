<?php

namespace App\Services;

use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Models\AiCreative;
use App\Models\GenerationOutput;
use App\Models\ImageBatch;
use App\Models\ImageBatchImage;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tạo ảnh theo lô (note/tach-lo-anh-dang-tiktok.txt): mỗi mẫu một lần trong lô, nhập nguyên liệu một lần, tạo N ảnh.
 * Lô tạo xong là cố định: chỉ tạo lại / sửa nguyên liệu / xoá từng ảnh, hoặc xoá cả lô.
 * Ảnh của lô là output của generation; tạo ảnh và tính phí đi qua GenerationService như POST /generations.
 */
class ImageBatchService
{
    public const BUSY = [OutputStatus::Queued, OutputStatus::Processing];

    public function __construct(
        private GenerationService $generations,
        private CreditService $credits,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /**
     * Trừ tổng phí ngay, đủ hoặc không tạo gì. Số ảnh một mẫu vượt max_outputs của mẫu thì chia thành nhiều
     * generation dùng chung ảnh đầu vào (chỉ tải lên một lần).
     *
     * @param  array<int, array{creative: AiCreative, input: GenerationInput, quantity: int}>  $items
     * @param  bool  $insertLogo  chèn logo shop vào mọi ảnh của lô (worker tạo ảnh chèn, note/logo-tao-anh.txt)
     */
    public function create(User $user, array $items, string $idempotencyKey, bool $insertLogo = false): ImageBatch
    {
        if ($existing = $this->findExisting($user, $idempotencyKey)) {
            return $existing;
        }

        $total = array_sum(array_map(fn (array $item) => $item['creative']->price_per_image * $item['quantity'], $items));

        try {
            return DB::transaction(function () use ($user, $items, $idempotencyKey, $total, $insertLogo) {
                // Kiểm tra tổng phí trước để báo đúng số tiền cần (không dừng giữa chừng ở mẫu thứ n)
                $this->credits->assertSufficient($this->credits->lockWallet($user->id), $total);

                $batch = ImageBatch::query()->create(['user_id' => $user->id, 'idempotency_key' => $idempotencyKey]);
                $position = 0;

                foreach (array_values($items) as $i => $item) {
                    $creative = $item['creative']->loadMissing('config');
                    $chunk = max(1, (int) $creative->config->max_outputs);
                    $input = $item['input'];
                    $kept = [];

                    for ($done = 0, $part = 0; $done < $item['quantity']; $done += $chunk, $part++) {
                        $quantity = min($chunk, $item['quantity'] - $done);
                        $generation = $this->generations->create($user, $creative, $input, $quantity, "batch:{$batch->id}:{$i}:{$part}", $kept);
                        // Cùng transaction với lúc tạo output nên worker chưa nhận job trước khi đặt cờ logo
                        if ($insertLogo) {
                            $generation->update(['insert_logo' => true]);
                        }

                        foreach ($generation->outputs as $output) {
                            $batch->images()->create([
                                'generation_id' => $generation->id,
                                'output_id' => $output->id,
                                'position' => ++$position,
                            ]);
                        }

                        // Phần sau dùng lại ảnh đầu vào đã lưu của phần đầu
                        $input = new GenerationInput([], $input->values, $input->aspectRatio);
                        $kept = $generation->input_paths ?? [];
                    }
                }

                $this->audit->log('image_batch.create', $batch, ['creatives' => count($items), 'images' => $position, 'total' => $total], $user->id);

                return $batch;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->findExisting($user, $idempotencyKey) ?? throw $e;
        }
    }

    /**
     * "Tạo lại" (inputs null: dùng lại nguyên liệu cũ) hoặc "Sửa nguyên liệu". Tính phí như lần đầu,
     * ảnh mới thay đúng vị trí ảnh cũ (ảnh cũ vẫn còn trong lịch sử tạo ảnh, bài đăng đã tạo không đổi).
     *
     * @param  array<string, string>  $keptPaths  ảnh đầu vào giữ lại theo tên trường (chỉ khi sửa nguyên liệu)
     */
    public function regenerate(User $user, ImageBatch $batch, ImageBatchImage $image, ?GenerationInput $input, array $keptPaths, string $idempotencyKey): ImageBatchImage
    {
        $original = $image->generation()->with('creative')->firstOrFail();
        $creative = $original->creative ?? throw new BusinessException('IMAGE_HAS_NO_CREATIVE', 'Không xác định được mẫu đã tạo ra ảnh này.', 409);

        return DB::transaction(function () use ($user, $batch, $image, $input, $keptPaths, $idempotencyKey, $original, $creative) {
            $image = $batch->images()->lockForUpdate()->findOrFail($image->id);

            if ($input === null) {
                $output = GenerationOutput::query()->findOrFail($image->output_id);
                if (! $output->status->isFinal()) {
                    throw new BusinessException('IMAGE_NOT_READY', 'Ảnh đang được tạo, vui lòng chờ xong rồi tạo lại.', 409);
                }
                $generation = $this->generations->retry($user, $original, [$output->id], $idempotencyKey);
            } else {
                $generation = $this->generations->create($user, $creative, $input, 1, $idempotencyKey, $keptPaths, $original);
            }

            $image->update(['generation_id' => $generation->id, 'output_id' => $generation->outputs->first()->id]);
            $this->audit->log('image_batch.regenerate', $batch, ['image_id' => $image->id, 'generation_id' => $generation->id], $user->id);

            return $image;
        });
    }

    /**
     * "Cắt ảnh": ảnh đã cắt trên trình duyệt ghi đè lên ảnh gốc của output (không tính phí). Ảnh trong lịch sử tạo
     * ảnh và bài đăng dùng cùng output cũng đổi theo. File mới có đường dẫn mới, file cũ bị xoá sau khi lưu xong.
     */
    public function crop(User $user, ImageBatch $batch, ImageBatchImage $image, UploadedFile $file): ImageBatchImage
    {
        // Lưu ảnh trước, ngoài transaction, để không giữ khóa trong lúc upload lên S3 (như WorkerJobService::complete)
        $directory = MediaStorage::datedDirectory('generations/outputs');
        $path = $this->media->storeUpload($file, $directory);
        try {
            $thumbPath = $this->media->storeOptimizedImage($file, $directory, [WorkerJobService::THUMB_MAX_SIDE])[0];
        } catch (BusinessException) {
            $thumbPath = null;
        }

        try {
            $old = DB::transaction(function () use ($user, $batch, $image, $file, $path, $thumbPath) {
                $image = $batch->images()->lockForUpdate()->findOrFail($image->id);
                $output = GenerationOutput::query()->lockForUpdate()->findOrFail($image->output_id);
                if ($output->status !== OutputStatus::Completed) {
                    throw new BusinessException('IMAGE_NOT_READY', 'Chỉ cắt được ảnh đã tạo xong.', 409);
                }

                $old = [$output->image_path, $output->thumb_path];
                $output->update([
                    'image_path' => $path,
                    'thumb_path' => $thumbPath,
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                ]);
                $this->audit->log('image_batch.crop', $batch, ['image_id' => $image->id, 'output_id' => $output->id], $user->id);

                return $old;
            });
        } catch (\Throwable $e) {
            $this->media->delete($path);
            $this->media->delete($thumbPath);
            throw $e;
        }

        foreach ($old as $oldPath) {
            $this->media->delete($oldPath);
        }

        return $image;
    }

    /** Xoá một ảnh khỏi lô (ảnh vẫn còn trong lịch sử tạo ảnh). Ảnh đang tạo / ảnh cuối cùng: không xoá được. */
    public function deleteImage(User $user, ImageBatch $batch, ImageBatchImage $image): void
    {
        DB::transaction(function () use ($user, $batch, $image) {
            $image = $batch->images()->lockForUpdate()->with('output')->findOrFail($image->id);
            if (in_array($image->output?->status, self::BUSY, true)) {
                throw new BusinessException('IMAGE_NOT_READY', 'Ảnh đang được tạo, vui lòng chờ xong rồi xoá.', 409);
            }
            if ($batch->images()->count() <= 1) {
                throw new BusinessException('BATCH_LAST_IMAGE', 'Lô phải còn ít nhất 1 ảnh. Muốn bỏ hết thì xoá cả lô.', 409);
            }

            $this->audit->log('image_batch.delete_image', $batch, ['image_id' => $image->id], $user->id);
            $image->delete();
        });
    }

    /** Xoá lô khi không còn ảnh đang tạo. Bài đăng đã tạo từ lô và ảnh trong lịch sử tạo ảnh vẫn giữ. */
    public function delete(User $user, ImageBatch $batch): void
    {
        DB::transaction(function () use ($user, $batch) {
            $batch = ImageBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $busy = $batch->images()->whereHas('output', fn ($q) => $q->whereIn('status', self::BUSY))->exists();
            if ($busy) {
                throw new BusinessException('BATCH_NOT_DELETABLE', 'Lô còn ảnh đang được tạo, vui lòng chờ xong rồi xoá.', 409);
            }

            $this->audit->log('image_batch.delete', $batch, ['images' => $batch->images()->count()], $user->id);
            $batch->delete();
        });
    }

    /**
     * Nguyên liệu đã dùng để tạo ảnh. Trả null khi file đầu vào không còn (đã xoá theo chính sách lưu trữ).
     *
     * @return array{creative: ?array, inputs: ?array}
     */
    public function imageInputs(ImageBatchImage $image): array
    {
        $generation = $image->generation()->with('creative.config')->firstOrFail();
        $creative = $generation->creative;
        $payload = ['creative' => $creative ? self::creativeSummary($creative) : null, 'inputs' => null];
        if (! $creative?->config) {
            return $payload;
        }

        $paths = $generation->input_paths ?? [];
        $values = $generation->input_values ?? [];
        $inputs = [];
        foreach (CreativeTemplate::buyerFields($creative->config->input_schema) as $field) {
            $key = $field['key'];
            if ($field['type'] !== 'image') {
                if (array_key_exists($key, $values)) {
                    $inputs[$key] = $values[$key];
                }

                continue;
            }

            $path = $paths[$key] ?? null;
            if ($path === null) {
                $inputs[$key] = [];

                continue;
            }
            if (! $this->media->disk()->exists($path)) {
                return $payload;
            }
            $inputs[$key] = [['file_id' => self::fileId($path), 'url' => $this->media->url($path), 'name' => basename($path)]];
        }
        $inputs[CreativeTemplate::ASPECT_KEY] ??= $generation->aspect_ratio;

        return [...$payload, 'inputs' => $inputs];
    }

    /**
     * keep_files[key][] = file_id -> đường dẫn ảnh cũ. Trường có ảnh mới tải lên thì bỏ qua ảnh giữ lại.
     *
     * @param  array<string, mixed>  $keepFiles
     * @param  string[]  $uploadedKeys
     * @return array<string, string>
     */
    public function keptPaths(ImageBatchImage $image, array $keepFiles, array $uploadedKeys): array
    {
        $paths = $image->generation->input_paths ?? [];
        $kept = [];
        $errors = [];

        foreach ($keepFiles as $key => $ids) {
            if (in_array($key, $uploadedKeys, true)) {
                continue;
            }
            $path = $paths[$key] ?? null;
            $ids = array_values((array) $ids);
            if (! $path || $ids !== [self::fileId($path)] || ! $this->media->disk()->exists($path)) {
                $errors["keep_files.{$key}"] = 'Ảnh cũ không còn, vui lòng tải ảnh lên lại.';

                continue;
            }
            $kept[$key] = $path;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $kept;
    }

    public static function creativeSummary(AiCreative $creative): array
    {
        return [
            'id' => $creative->id,
            'slug' => $creative->slug,
            'title' => $creative->title,
            'price_per_image' => $creative->price_per_image,
        ];
    }

    /** Mã file ổn định cho ảnh đầu vào (frontend gửi lại trong keep_files để giữ ảnh cũ). */
    public static function fileId(string $path): string
    {
        return 'f_'.substr(hash('sha256', $path), 0, 16);
    }

    private function findExisting(User $user, string $idempotencyKey): ?ImageBatch
    {
        return ImageBatch::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
