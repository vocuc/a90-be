<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\LedgerType;
use App\Enums\OutputStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\BusinessException;
use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tạo Generation: kiểm tra Creative, trừ Credit, tạo Transaction cho từng ảnh.
 * Các ảnh ở trạng thái queued chờ worker nhận qua GET /api/v1/jobs (xem WorkerJobService).
 * Giá, model, prompt, phí luôn đọc từ database, không bao giờ lấy từ request.
 */
class GenerationService
{
    public function __construct(
        private CreditService $credits,
        private RevenueShareService $shares,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /**
     * Người mua (kể cả chính Creator) tạo ảnh từ Creative đang xuất bản: luôn trừ Credit.
     *
     * @param  array<string, string>  $keptPaths  ảnh đầu vào dùng lại từ lần tạo trước theo tên trường (khi không tải ảnh mới)
     */
    public function create(User $user, AiCreative $creative, GenerationInput $input, int $quantity, string $idempotencyKey, array $keptPaths = [], ?Generation $parent = null): Generation
    {
        if ($existing = $this->findExisting($user, $idempotencyKey, $creative->id)) {
            return $existing;
        }

        $this->assertAvailable($creative, $user);

        // Creator tự dùng mẫu của mình cũng bị trừ Credit như người mua (tạo thử miễn phí dùng createTest)
        // Sửa nguyên liệu một ảnh của lô ($parent): giữ bật / tắt logo của ảnh cũ
        return $this->start($user, $creative, GenerationType::Paid, $quantity, $input->aspectRatio, $idempotencyKey,
            fn () => [...$this->storeInput($input, $keptPaths), 'insert_logo' => (bool) $parent?->insert_logo], $parent);
    }

    /** Creator tạo thử (kể cả bản nháp): không trừ Credit. */
    public function createTest(User $creator, AiCreative $creative, GenerationInput $input, int $quantity, string $idempotencyKey): Generation
    {
        if ($existing = $this->findExisting($creator, $idempotencyKey, $creative->id)) {
            return $existing;
        }

        return $this->start($creator, $creative, GenerationType::Test, $quantity, $input->aspectRatio, $idempotencyKey,
            fn () => $this->storeInput($input));
    }

    /**
     * Tạo lại các ảnh được chọn: Generation mới, trừ Credit mới, Transaction mới.
     *
     * @param  int[]  $outputIds
     */
    public function retry(User $user, Generation $original, array $outputIds, string $idempotencyKey): Generation
    {
        $creative = $original->creative()->firstOrFail();
        if ($existing = $this->findExisting($user, $idempotencyKey, $creative->id)) {
            return $existing;
        }

        $outputs = $original->outputs()->whereIn('id', $outputIds)->get();
        if ($outputs->count() !== count(array_unique($outputIds))) {
            throw ValidationException::withMessages(['output_ids' => 'Có ảnh không thuộc lần tạo này.']);
        }
        if ($outputs->contains(fn (GenerationOutput $o) => ! $o->status->isFinal())) {
            throw ValidationException::withMessages(['output_ids' => 'Chỉ retry được ảnh đã xử lý xong.']);
        }

        if ($original->type === GenerationType::Test) {
            $type = GenerationType::Test;
        } else {
            $this->assertAvailable($creative, $user);
            $type = GenerationType::Paid;
        }

        return $this->start($user, $creative, $type, $outputs->count(), $original->aspect_ratio, $idempotencyKey,
            fn () => [
                'input_paths' => $original->input_paths,
                'asset_paths' => $original->asset_paths,
                'input_values' => $original->input_values,
                'insert_logo' => $original->insert_logo,
            ],
            $original, $outputs->pluck('id')->all());
    }

    /**
     * @param  callable(): array{input_paths: array, asset_paths?: ?array, input_values: ?array}  $resolveInput
     * @param  int[]  $retryOfOutputIds
     */
    private function start(
        User $user,
        AiCreative $creative,
        GenerationType $type,
        int $quantity,
        string $aspectRatio,
        string $idempotencyKey,
        callable $resolveInput,
        ?Generation $parent = null,
        array $retryOfOutputIds = [],
    ): Generation {
        $creative->loadMissing(['config', 'model']);
        $this->assertOptions($creative, $quantity, $aspectRatio);

        if (! $creative->model->is_active) {
            throw BusinessException::creativeTemporarilyUnavailable();
        }

        $input = $resolveInput();
        // Lần tạo lại dùng đúng nguyên liệu ẩn của lần gốc
        if (! array_key_exists('asset_paths', $input)) {
            $input['asset_paths'] = $this->assetPaths($creative);
        }

        try {
            $generation = DB::transaction(function () use ($user, $creative, $type, $quantity, $aspectRatio, $idempotencyKey, $input, $parent, $retryOfOutputIds) {
                $paid = $type === GenerationType::Paid;
                $price = $creative->price_per_image;
                $wallet = null;

                if ($paid) {
                    $wallet = $this->credits->lockWallet($user->id);
                    $this->credits->assertSufficient($wallet, $price * $quantity);
                    $sharePercent = $this->shares->percentFor($creative->creator_id);
                    $split = $this->shares->split($price, $sharePercent);
                }

                $config = $creative->config;
                $generation = Generation::query()->create([
                    'user_id' => $user->id,
                    'creative_id' => $creative->id,
                    'creator_id' => $creative->creator_id,
                    'model_id' => $creative->model_id,
                    'parent_generation_id' => $parent?->id,
                    'type' => $type,
                    ...$input,
                    'aspect_ratio' => $aspectRatio,
                    'quantity' => $quantity,
                    'price_per_image' => $paid ? $price : 0,
                    'prompt_snapshot' => CreativeTemplate::render($config->prompt_template, $config->input_schema, $input['input_values'] ?? []),
                    'status' => GenerationStatus::Queued,
                    'idempotency_key' => $idempotencyKey,
                ]);

                for ($i = 0; $i < $quantity; $i++) {
                    $output = $generation->outputs()->create([
                        'position' => $i + 1,
                        'retry_of_output_id' => $retryOfOutputIds[$i] ?? null,
                        'status' => OutputStatus::Queued,
                    ]);

                    if (! $paid) {
                        continue;
                    }

                    $transaction = Transaction::query()->create([
                        'code' => $this->transactionCode(),
                        'user_id' => $user->id,
                        'creator_id' => $creative->creator_id,
                        'creative_id' => $creative->id,
                        'generation_id' => $generation->id,
                        'generation_output_id' => $output->id,
                        'model_code' => $creative->model->code,
                        'amount' => $price,
                        'creator_share_percent' => $sharePercent,
                        ...$split,
                        'status' => TransactionStatus::Processing,
                    ]);
                    $output->update(['transaction_id' => $transaction->id]);

                    $this->credits->apply($wallet, -$price, LedgerType::GenerationCharge, "charge:tx:{$transaction->id}", 'transaction', $transaction->id);
                }

                $this->audit->log('generation.create', $generation, [
                    'type' => $type->value,
                    'quantity' => $quantity,
                    'total' => $paid ? $price * $quantity : 0,
                ], $user->id);

                return $generation;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Hai request cùng idempotency_key chạy song song: trả về bản đã tạo.
            if ($existing = $this->findExisting($user, $idempotencyKey, $creative->id)) {
                return $existing;
            }
            throw $e;
        }

        return $generation->load('outputs');
    }

    /**
     * Lưu ảnh đầu vào lên S3 theo tên trường; trường không có ảnh mới thì dùng ảnh giữ lại.
     *
     * @param  array<string, string>  $keptPaths
     */
    private function storeInput(GenerationInput $input, array $keptPaths = []): array
    {
        $directory = MediaStorage::datedDirectory('generations/inputs');

        return [
            'input_paths' => [
                ...$keptPaths,
                ...array_map(fn ($file) => $this->media->storeUpload($file, $directory), $input->files),
            ],
            'input_values' => $input->values,
        ];
    }

    /**
     * Ảnh nguyên liệu ẩn hiện tại của Creative theo tên trường.
     *
     * @return array<string, string>|null
     */
    private function assetPaths(AiCreative $creative): ?array
    {
        $ids = CreativeTemplate::hiddenAssetIds($creative->config->input_schema ?? []);
        if (! $ids) {
            return null;
        }

        $paths = $creative->assets()->whereIn('id', $ids)->pluck('path', 'id');

        return array_filter(array_map(fn (int $id) => $paths[$id] ?? null, $ids));
    }

    private function findExisting(User $user, string $idempotencyKey, int $creativeId): ?Generation
    {
        $existing = Generation::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing && $existing->creative_id !== $creativeId) {
            throw BusinessException::idempotencyConflict();
        }

        return $existing?->load('outputs');
    }

    private function assertAvailable(AiCreative $creative, User $user): void
    {
        if (! $creative->isAvailableTo($user)) {
            throw BusinessException::creativeNotAvailable();
        }
    }

    private function assertOptions(AiCreative $creative, int $quantity, string $aspectRatio): void
    {
        $max = $creative->config->max_outputs;
        if ($quantity < 1 || $quantity > $max) {
            throw new BusinessException('QUANTITY_EXCEEDED', "Số lượng ảnh phải từ 1 đến {$max}.", 422, ['max' => $max]);
        }

        if (! in_array($aspectRatio, self::allowedAspectRatios($creative), true)) {
            throw new BusinessException('ASPECT_RATIO_NOT_ALLOWED', 'Tỉ lệ khung hình không được hỗ trợ.', 422, [
                'allowed' => self::allowedAspectRatios($creative),
            ]);
        }
    }

    /** Tỉ lệ khung hình người dùng được chọn: mọi tỉ lệ model của Creative hỗ trợ. */
    public static function allowedAspectRatios(AiCreative $creative): array
    {
        return array_values($creative->model->supported_aspect_ratios ?? []);
    }

    private function transactionCode(): string
    {
        return 'TX'.now()->format('ymd').Str::upper(Str::random(10));
    }
}
