<?php

namespace App\Services\TikTok;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use App\Models\TikTokContentJob;
use App\Models\TikTokPost;
use App\Models\TikTokPostImage;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bài đăng TikTok: tạo bài từ ảnh có sẵn (của một lô hoặc lịch sử tạo ảnh), xoá bài. Xem note/nghiep-vu-dang-bai-tiktik.txt.
 * Tạo ảnh / tạo lại ảnh nằm ở lô ảnh (ImageBatchService).
 */
class TikTokPostService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Tạo bài từ ảnh của một lô hoặc ảnh đã có trên A51 (không tính phí). Ảnh đã xong nên bài sang bước nội dung ngay.
     * Bài và lô độc lập: tạo lại / xoá ảnh trong lô sau đó không đổi bài.
     *
     * @param  array<int, array{generation_id: int, output_id: int}>  $refs
     * @param  array{title?: ?string, content?: ?string, hashtags?: ?array}  $content  người dùng tự nhập (không bắt buộc)
     */
    public function createFromImages(User $user, array $refs, string $idempotencyKey, array $content = [], ?int $batchId = null): TikTokPost
    {
        $source = $batchId ? TikTokPost::SOURCE_BATCH : TikTokPost::SOURCE_EXISTING;
        if ($existing = $this->findExisting($user, $idempotencyKey, $source)) {
            return $existing;
        }

        $outputs = $this->resolveOwnedOutputs($user, $refs, 'images');

        try {
            $post = DB::transaction(function () use ($user, $outputs, $idempotencyKey, $content, $source, $batchId) {
                $post = TikTokPost::query()->create([
                    'user_id' => $user->id,
                    'status' => TikTokPostStatus::Generating,
                    'source' => $source,
                    'image_batch_id' => $batchId,
                    'idempotency_key' => $idempotencyKey,
                    ...self::manualContent($content),
                ]);
                foreach ($outputs->values() as $i => $output) {
                    $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
                }
                $this->imagesReady($post);

                $this->audit->log('tiktok_post.create', $post, ['source' => $source, 'image_batch_id' => $batchId, 'images' => $outputs->count()], $user->id);

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
        $postIds = TikTokPostImage::query()
            ->where('output_id', $outputId)
            ->whereHas('post', fn ($q) => $q->where('status', TikTokPostStatus::Generating))
            ->pluck('tiktok_post_id');

        foreach ($postIds as $postId) {
            $this->advanceIfReady($postId);
        }
    }

    /** Idempotent: gọi lặp (listener, lệnh sweep) không tạo job thứ 2. */
    public function advanceIfReady(int $postId): void
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
                $this->imagesReady($post);
            }
        });
    }

    /**
     * Ảnh của bài đã xong: giao worker viết nội dung (bật ai_content và người dùng chưa tự nhập),
     * không thì sang chờ duyệt ngay (chưa nhập gì: lấy tên mẫu + hashtag của mẫu).
     */
    private function imagesReady(TikTokPost $post): void
    {
        $manual = $post->hasManualContent();
        if (config('ai90.tiktok.ai_content') && ! $manual) {
            TikTokContentJob::query()->firstOrCreate(['tiktok_post_id' => $post->id], ['status' => OutputStatus::Queued]);

            return;
        }

        $post->update([
            ...($manual ? [] : $post->defaultContent()),
            'status' => TikTokPostStatus::PendingReview,
        ]);
    }

    /** @param  array{title?: ?string, content?: ?string, hashtags?: ?array}  $content */
    private static function manualContent(array $content): array
    {
        return [
            'title' => trim((string) ($content['title'] ?? '')),
            'content' => $content['content'] ?? null,
            'hashtags' => TikTokPost::normalizeHashtags($content['hashtags'] ?? []) ?: null,
        ];
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
