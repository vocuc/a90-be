<?php

namespace App\Services\Social;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CroppedImageStore;
use App\Services\GenerationFinalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bài đăng (TikTok / Facebook / Instagram): tạo bài từ ảnh có sẵn (của một lô hoặc lịch sử tạo ảnh), xoá bài. Xem note/nghiep-vu-dang-bai-tiktik.txt.
 * Tạo ảnh / tạo lại ảnh nằm ở lô ảnh (ImageBatchService).
 */
class SocialPostService
{
    public function __construct(
        private AuditLogger $audit,
        private SocialContentJobService $contentJobs,
        private GenerationFinalizer $finalizer,
        private CroppedImageStore $croppedImages,
    ) {}

    /**
     * Tạo bài từ ảnh của một lô hoặc ảnh đã có trên A51 (không tính phí). Ảnh đã xong nên bài sang bước nội dung ngay.
     * Bài và lô độc lập: tạo lại / xoá ảnh trong lô sau đó không đổi bài.
     * $aiContent: người dùng bật "AI viết nội dung" (chỉ có tác dụng khi bật ai90.social.ai_content và không tự nhập
     * nội dung): trừ Credit ngay, không đủ thì lỗi 402 và không tạo bài.
     *
     * @param  array<int, array{generation_id: int, output_id: int}>  $refs
     * @param  array{title?: ?string, content?: ?string, hashtags?: ?array}  $content  người dùng tự nhập (không bắt buộc)
     */
    public function createFromImages(User $user, array $refs, string $idempotencyKey, array $content = [], ?int $batchId = null, bool $aiContent = false): SocialPost
    {
        $source = $batchId ? SocialPost::SOURCE_BATCH : SocialPost::SOURCE_EXISTING;
        if ($existing = $this->findExisting($user, $idempotencyKey, $source)) {
            return $existing;
        }

        $outputs = $this->resolveOwnedOutputs($user, $refs, 'images');

        try {
            $manual = self::manualContent($content);
            $aiContent = $aiContent && SocialContentJobService::enabled() && $manual['title'] === '' && trim((string) $manual['content']) === '' && ! $manual['hashtags'];
            $post = DB::transaction(function () use ($user, $outputs, $idempotencyKey, $manual, $source, $batchId, $aiContent) {
                $post = SocialPost::query()->create([
                    'user_id' => $user->id,
                    'status' => SocialPostStatus::Generating,
                    'source' => $source,
                    'ai_content' => $aiContent,
                    'image_batch_id' => $batchId,
                    'idempotency_key' => $idempotencyKey,
                    ...$manual,
                ]);
                foreach ($outputs->values() as $i => $output) {
                    $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
                }
                $this->imagesReady($post);

                $this->audit->log('social_post.create', $post, ['source' => $source, 'image_batch_id' => $batchId, 'images' => $outputs->count(), 'ai_content' => $aiContent], $user->id);

                return $post;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->findExisting($user, $idempotencyKey, $source) ?? throw $e;
        }

        return $post;
    }

    /** Một ảnh vừa xong: bài đang generating có đủ ảnh xong thì sang bước nội dung. */
    public function outputFinished(int $outputId): void
    {
        $postIds = SocialPostImage::query()
            ->where('output_id', $outputId)
            ->whereHas('post', fn ($q) => $q->where('status', SocialPostStatus::Generating))
            ->pluck('social_post_id');

        foreach ($postIds as $postId) {
            $this->advanceIfReady($postId);
        }
    }

    /** Idempotent: gọi lặp (listener, lệnh sweep) không tạo job thứ 2. */
    public function advanceIfReady(int $postId): void
    {
        DB::transaction(function () use ($postId) {
            $post = SocialPost::query()->lockForUpdate()->find($postId);
            if (! $post || $post->status !== SocialPostStatus::Generating) {
                return;
            }

            $pending = $post->images()
                ->whereHas('output', fn ($q) => $q->whereIn('status', [OutputStatus::Queued, OutputStatus::Processing]))
                ->exists();
            if (! $pending) {
                $this->imagesReady($post);
            }
        });
    }

    /**
     * Ảnh của bài đã xong: bài bật AI viết nội dung thì trừ Credit và giao AI viết,
     * không thì sang chờ duyệt ngay (chưa nhập gì: lấy tên mẫu + hashtag của mẫu).
     */
    private function imagesReady(SocialPost $post): void
    {
        // Bài kế hoạch có ảnh lỗi: không viết nội dung, chờ chủ shop bấm "Làm lại" (PlannerService::redo)
        if ($post->isPlan() && $post->images()->whereHas('output', fn ($q) => $q->where('status', OutputStatus::Failed))->exists()) {
            $post->update([
                ...$post->defaultContent(),
                'status' => SocialPostStatus::PendingReview,
                'last_error' => ['code' => SocialPost::PLAN_IMAGES_FAILED, 'message' => 'Có ảnh tạo lỗi, bấm "Làm lại" để tạo lại bài theo kịch bản.', 'platforms' => []],
            ]);

            return;
        }

        // Bài kế hoạch tạo lại 1 ảnh (PlannerService::redoImage) đã có nội dung: giữ nguyên, không viết lại
        if ($post->ai_content && ! ($post->isPlan() && $post->hasManualContent())) {
            $this->contentJobs->queue($post);

            return;
        }

        $manual = $post->hasManualContent();

        $post->update([
            ...($manual ? [] : $post->defaultContent()),
            'status' => SocialPostStatus::PendingReview,
        ]);
    }

    /** @param  array{title?: ?string, content?: ?string, hashtags?: ?array}  $content */
    private static function manualContent(array $content): array
    {
        return [
            'title' => trim((string) ($content['title'] ?? '')),
            'content' => $content['content'] ?? null,
            'hashtags' => SocialPost::normalizeHashtags($content['hashtags'] ?? []) ?: null,
        ];
    }

    /**
     * Xoá bài khi đang chờ gửi hoặc chờ tới giờ (worker chưa nhận) và chưa đăng xong nền tảng nào. Đang tạo nội dung /
     * đang đăng / đã đăng: không xoá được (note/gop-luong-dang-bai.txt ở repo frontend).
     */
    public function delete(User $user, SocialPost $post): void
    {
        DB::transaction(function () use ($user, $post) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $published = collect($post->platformStatuses())->filter(fn ($status) => $status === PlatformPublishStatus::Published)->keys();
            $deletable = $post->isEditable();
            if (! $deletable || $published->isNotEmpty()) {
                throw new BusinessException('POST_NOT_DELETABLE', match ($post->status) {
                    SocialPostStatus::Generating => 'Bài đang được tạo nội dung, vui lòng chờ xong rồi xoá.',
                    SocialPostStatus::Publishing => 'Worker đang đăng bài, không xoá được.',
                    default => 'Bài đã đăng lên '.$published->map(fn ($p) => SocialPlatform::from($p)->label())->implode(', ').', không xoá được trên A51.',
                }, 409);
            }

            $this->audit->log('social_post.delete', $post, ['images' => $post->images()->count()], $user->id);
            $post->delete();
        });
    }

    /**
     * "Hủy bỏ" bài đang chuẩn bị: ảnh còn trong hàng đợi (chưa worker nhận) thành thất bại CANCELLED và hoàn Credit,
     * hoàn Credit AI viết nội dung nếu AI chưa xong, rồi xoá bài. Ảnh worker đang tạo vẫn chạy xong, nằm trong lịch sử
     * tạo ảnh, không gắn vào bài nữa. Bài kế hoạch bị xoá thì giờ đó trống lại, lần lập kế hoạch sau sẽ bù.
     */
    public function cancel(User $user, SocialPost $post): void
    {
        DB::transaction(function () use ($user, $post) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            if ($post->status !== SocialPostStatus::Generating) {
                throw new BusinessException('POST_NOT_CANCELLABLE', 'Bài không còn ở bước chuẩn bị nên không hủy được.', 409);
            }

            $queued = GenerationOutput::query()
                ->whereIn('id', $post->images()->select('output_id'))
                ->where('status', OutputStatus::Queued)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            foreach ($queued as $id) {
                $this->finalizer->fail($id, GenerationFinalizer::CANCELLED, 'Đã hủy bài đăng, Credit đã được hoàn.');
            }
            $this->contentJobs->cancelForPost($post);

            $this->audit->log('social_post.cancel', $post, ['source' => $post->source, 'cancelled_images' => $queued->count()], $user->id);
            $post->delete();
        });
    }

    /**
     * "Cắt ảnh" trong bài đang chờ gửi: ảnh đã cắt trên trình duyệt ghi đè ảnh gốc (giống cắt ảnh ở lô): ảnh tạo trên
     * A51 thì lô, lịch sử tạo ảnh và mọi bài dùng ảnh đó đổi theo; ảnh upload thẳng (lookbook) chỉ có ở bài này.
     */
    public function cropImage(User $user, SocialPost $post, int $imageId, UploadedFile $file): SocialPostImage
    {
        $directory = $post->images()->findOrFail($imageId)->isUploaded()
            ? "social-posts/{$post->user_id}/crop"
            : 'generations/outputs';

        $this->croppedImages->replace($file, $directory, function (array $stored) use ($user, $post, $imageId) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $this->assertEditable($post);
            $image = $post->images()->lockForUpdate()->findOrFail($imageId);

            if ($image->isUploaded()) {
                $old = [$image->image_path, $image->thumb_path];
                $image->update(['image_path' => $stored['image_path'], 'thumb_path' => $stored['thumb_path']]);
            } else {
                $old = $this->croppedImages->overwriteOutput($image->output_id, $stored);
            }
            $this->audit->log('social_post.crop_image', $post, ['image_id' => $image->id, 'output_id' => $image->output_id], $user->id);

            return $old;
        });

        return $post->images()->with(['output', 'generation.creative'])->findOrFail($imageId);
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
            if (! $output || $output->generation_id !== (int) $ref['generation_id'] || ! $output->isUsable()) {
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

    /** Chờ gửi hoặc chờ tới giờ (worker chưa nhận). */
    public function assertEditable(SocialPost $post): void
    {
        if (! $post->isEditable()) {
            throw BusinessException::postNotEditable();
        }
    }

    private function findExisting(User $user, string $idempotencyKey, string $source): ?SocialPost
    {
        $existing = SocialPost::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing && $existing->source !== $source) {
            throw BusinessException::idempotencyConflict();
        }

        return $existing;
    }
}
