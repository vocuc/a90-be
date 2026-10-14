<?php

namespace App\Services\TikTok;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Exceptions\BusinessException;
use App\Models\AiCreative;
use App\Models\GenerationOutput;
use App\Models\TikTokContentJob;
use App\Models\TikTokPost;
use App\Models\TikTokPostImage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CreativeTemplate;
use App\Services\CreditService;
use App\Services\GenerationInput;
use App\Services\GenerationService;
use App\Services\MediaStorage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bài đăng TikTok: tạo bài (từ ảnh đã có / tạo ảnh mới), tạo lại ảnh, xoá bài. Xem note/nghiep-vu-dang-bai-tiktik.txt.
 * Ảnh của bài là output của generation; tạo ảnh và tính phí đi qua GenerationService như POST /generations.
 */
class TikTokPostService
{
    public function __construct(
        private GenerationService $generations,
        private CreditService $credits,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /**
     * Bước 2b: dùng ảnh đã tạo trên A51 (không tính phí). Bài ở generating trong lúc worker viết nội dung.
     *
     * @param  array<int, array{generation_id: int, output_id: int}>  $refs
     */
    public function createFromImages(User $user, array $refs, string $idempotencyKey): TikTokPost
    {
        if ($existing = $this->findExisting($user, $idempotencyKey, TikTokPost::SOURCE_EXISTING)) {
            return $existing;
        }

        $outputs = $this->resolveOwnedOutputs($user, $refs, 'images');

        try {
            $post = DB::transaction(function () use ($user, $outputs, $idempotencyKey) {
                $post = TikTokPost::query()->create([
                    'user_id' => $user->id,
                    'status' => TikTokPostStatus::Generating,
                    'source' => TikTokPost::SOURCE_EXISTING,
                    'idempotency_key' => $idempotencyKey,
                ]);
                foreach ($outputs->values() as $i => $output) {
                    $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
                }
                TikTokContentJob::query()->create(['tiktok_post_id' => $post->id, 'status' => OutputStatus::Queued]);

                $this->audit->log('tiktok_post.create', $post, ['source' => 'existing', 'images' => $outputs->count()], $user->id);

                return $post;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->findExisting($user, $idempotencyKey, TikTokPost::SOURCE_EXISTING) ?? throw $e;
        }

        return $post;
    }

    /**
     * Bước 2a: mỗi mẫu tạo 1 ảnh (1 generation quantity=1). Trừ tổng phí ngay, đủ hoặc không tạo gì.
     *
     * @param  array<int, array{creative: AiCreative, input: GenerationInput}>  $items
     */
    public function createFromCreatives(User $user, array $items, string $idempotencyKey): TikTokPost
    {
        if ($existing = $this->findExisting($user, $idempotencyKey, TikTokPost::SOURCE_NEW)) {
            return $existing;
        }

        $total = array_sum(array_map(fn (array $item) => $item['creative']->price_per_image, $items));

        try {
            $post = DB::transaction(function () use ($user, $items, $idempotencyKey, $total) {
                // Kiểm tra tổng phí trước để báo đúng số tiền cần (không dừng giữa chừng ở mẫu thứ n)
                $this->credits->assertSufficient($this->credits->lockWallet($user->id), $total);

                $post = TikTokPost::query()->create([
                    'user_id' => $user->id,
                    'status' => TikTokPostStatus::Generating,
                    'source' => TikTokPost::SOURCE_NEW,
                    'idempotency_key' => $idempotencyKey,
                ]);

                foreach (array_values($items) as $i => $item) {
                    $generation = $this->generations->create($user, $item['creative'], $item['input'], 1, "tiktok:{$idempotencyKey}:{$i}");
                    $post->images()->create([
                        'generation_id' => $generation->id,
                        'output_id' => $generation->outputs->first()->id,
                        'position' => $i + 1,
                    ]);
                }

                $this->audit->log('tiktok_post.create', $post, ['source' => 'new', 'images' => count($items), 'total' => $total], $user->id);

                return $post;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->findExisting($user, $idempotencyKey, TikTokPost::SOURCE_NEW) ?? throw $e;
        }

        return $post;
    }

    /** Một ảnh vừa xong: bài đang generating có đủ ảnh xong thì giao job viết nội dung. */
    public function outputFinished(int $outputId): void
    {
        $postIds = TikTokPostImage::query()
            ->where('output_id', $outputId)
            ->whereHas('post', fn ($q) => $q->where('status', TikTokPostStatus::Generating))
            ->pluck('tiktok_post_id');

        foreach ($postIds as $postId) {
            $this->enqueueContentIfReady($postId);
        }
    }

    /** Idempotent: gọi lặp (listener, lệnh sweep) không tạo job thứ 2. */
    public function enqueueContentIfReady(int $postId): void
    {
        DB::transaction(function () use ($postId) {
            $post = TikTokPost::query()->lockForUpdate()->find($postId);
            if (! $post || $post->status !== TikTokPostStatus::Generating) {
                return;
            }

            $pending = $post->images()
                ->whereHas('output', fn ($q) => $q->whereIn('status', [OutputStatus::Queued, OutputStatus::Processing]))
                ->exists();
            if (! $pending) {
                TikTokContentJob::query()->firstOrCreate(['tiktok_post_id' => $post->id], ['status' => OutputStatus::Queued]);
            }
        });
    }

    /**
     * "Tạo lại" (inputs null: dùng lại nguyên liệu cũ) hoặc "Sửa nguyên liệu". Tính phí như lần đầu,
     * ảnh mới thay đúng vị trí ảnh cũ (ảnh cũ vẫn còn trong lịch sử tạo ảnh).
     *
     * @param  array<string, string>  $keptPaths  ảnh đầu vào giữ lại theo tên trường (chỉ khi sửa nguyên liệu)
     */
    public function regenerate(User $user, TikTokPost $post, TikTokPostImage $image, ?GenerationInput $input, array $keptPaths, string $idempotencyKey): TikTokPostImage
    {
        $this->assertEditable($post);
        $original = $image->generation()->with('creative')->firstOrFail();
        $creative = $original->creative ?? throw new BusinessException('IMAGE_HAS_NO_CREATIVE', 'Không xác định được mẫu đã tạo ra ảnh này.', 409);

        return DB::transaction(function () use ($user, $post, $image, $input, $keptPaths, $idempotencyKey, $original, $creative) {
            $post = TikTokPost::query()->lockForUpdate()->findOrFail($post->id);
            $this->assertEditable($post);
            $image = $post->images()->lockForUpdate()->findOrFail($image->id);

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
            $this->audit->log('tiktok_post.regenerate', $post, ['image_id' => $image->id, 'generation_id' => $generation->id], $user->id);

            return $image;
        });
    }

    /**
     * Nguyên liệu đã dùng để tạo ảnh. Trả null khi file đầu vào không còn (đã xoá theo chính sách lưu trữ).
     *
     * @return array{creative: ?array, inputs: ?array}
     */
    public function imageInputs(TikTokPostImage $image): array
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
        foreach ($creative->config->input_schema as $field) {
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
    public function keptPaths(TikTokPostImage $image, array $keepFiles, array $uploadedKeys): array
    {
        $generation = $image->generation;
        $paths = $generation->input_paths ?? [];
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

    /** Xoá bài khi đang chờ duyệt. Đang tạo nội dung / đang đăng / đã đăng: không xoá được. */
    public function delete(User $user, TikTokPost $post): void
    {
        DB::transaction(function () use ($user, $post) {
            $post = TikTokPost::query()->lockForUpdate()->findOrFail($post->id);
            if ($post->status !== TikTokPostStatus::PendingReview) {
                throw new BusinessException('POST_NOT_DELETABLE', match ($post->status) {
                    TikTokPostStatus::Generating => 'Bài đang được tạo nội dung, vui lòng chờ xong rồi xoá.',
                    default => 'Bài đã gửi lên TikTok, không xoá được trên A51.',
                }, 409);
            }

            $this->audit->log('tiktok_post.delete', $post, ['images' => $post->images()->count()], $user->id);
            $post->delete();
        });
    }

    /**
     * Ảnh "đã có": output thuộc generation của chính người dùng, đã xong và còn ảnh. Giữ thứ tự gửi lên.
     *
     * @param  array<int, array{generation_id: int, output_id: int}>  $refs
     * @return Collection<int, GenerationOutput>
     */
    public function resolveOwnedOutputs(User $user, array $refs, string $field): Collection
    {
        $outputs = GenerationOutput::query()
            ->whereIn('id', array_map(fn ($ref) => (int) $ref['output_id'], $refs))
            ->whereHas('generation', fn ($q) => $q->where('user_id', $user->id))
            ->get()
            ->keyBy('id');

        $errors = [];
        $result = collect();
        foreach ($refs as $i => $ref) {
            $output = $outputs->get((int) $ref['output_id']);
            if (! $output || $output->generation_id !== (int) $ref['generation_id']
                || $output->status !== OutputStatus::Completed || ! $output->image_path) {
                $errors["{$field}.{$i}"] = 'Ảnh không hợp lệ hoặc không thuộc tài khoản của bạn.';

                continue;
            }
            $result->put($i, $output);
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    public function assertEditable(TikTokPost $post): void
    {
        if ($post->status !== TikTokPostStatus::PendingReview) {
            throw BusinessException::postNotEditable();
        }
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

    private function findExisting(User $user, string $idempotencyKey, string $source): ?TikTokPost
    {
        $existing = TikTokPost::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing && $existing->source !== $source) {
            throw BusinessException::idempotencyConflict();
        }

        return $existing;
    }
}
