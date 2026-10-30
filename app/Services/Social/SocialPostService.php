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
use Illuminate\Database\UniqueConstraintViolationException;
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
        if ($post->ai_content) {
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

    /** Xoá bài khi đang chờ gửi và chưa đăng xong nền tảng nào. Đang tạo nội dung / chờ đăng / đang đăng / đã đăng: không xoá được. */
    public function delete(User $user, SocialPost $post): void
    {
        DB::transaction(function () use ($user, $post) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $published = collect($post->platformStatuses())->filter(fn ($status) => $status === PlatformPublishStatus::Published)->keys();
            if ($post->status !== SocialPostStatus::PendingReview || $published->isNotEmpty()) {
                throw new BusinessException('POST_NOT_DELETABLE', match ($post->status) {
                    SocialPostStatus::Generating => 'Bài đang được tạo nội dung, vui lòng chờ xong rồi xoá.',
                    SocialPostStatus::Queued, SocialPostStatus::Publishing => 'Bài đang được đăng, không xoá được.',
                    default => 'Bài đã đăng lên '.$published->map(fn ($p) => SocialPlatform::from($p)->label())->implode(', ').', không xoá được trên A51.',
                }, 409);
            }

            $this->audit->log('social_post.delete', $post, ['images' => $post->images()->count()], $user->id);
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

    public function assertEditable(SocialPost $post): void
    {
        if ($post->status !== SocialPostStatus::PendingReview) {
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
