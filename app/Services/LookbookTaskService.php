<?php

namespace App\Services;

use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\LookbookTask;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Lịch sử + giao việc tạo lookbook (FlowKit a51-skills/tao-anh-look-book/thiet-ke.txt mục 3).
 * A51 không giữ số lượng mỗi ngày (skill tự đếm); chỉ chọn sản phẩm tiếp theo và ghi lại kết quả.
 */
class LookbookTaskService
{
    /** Cạnh dài thumbnail của ảnh lookbook; ảnh gốc giữ nguyên file upload để đăng TikTok đúng chất lượng. */
    public const THUMB_MAX_SIDE = 600;

    public function __construct(private MediaStorage $media, private AuditLogger $audit) {}

    /**
     * Chọn sản phẩm tiếp theo của $user: sản phẩm của mình HOẶC được shop khác chia sẻ (product_shares), đang bán, có
     * ảnh field_key = $imageField; bỏ sản phẩm $user đang có task processing (chưa quá hạn) hoặc đã lỗi hôm nay. Sản phẩm
     * $user chưa làm lookbook trước, sau đó tới sản phẩm có lookbook gần nhất (của $user) cũ nhất. Không có -> null.
     * Lịch sử tính theo cặp (shop, sản phẩm): shop A làm lookbook sản phẩm X không ảnh hưởng thứ tự / khoá / outfit đã
     * dùng của shop B với cùng sản phẩm X (mỗi shop có tài khoản OOTD riêng, id outfit không dùng chung được).
     */
    public function next(Worker $worker, User $user, string $imageField): ?LookbookTask
    {
        return DB::transaction(function () use ($worker, $user, $imageField) {
            // Khoá theo tài khoản: hai lần gọi cùng lúc không nhận cùng một sản phẩm
            User::query()->lockForUpdate()->find($user->id);

            $staleBefore = now()->subMinutes(LookbookTask::STALE_MINUTES);
            $product = Product::query()
                ->accessibleBy($user)
                ->active()
                ->whereHas('images', fn (Builder $q) => $q->where('field_key', $imageField))
                ->whereNotExists(fn ($q) => $q->from('lookbook_tasks')
                    ->whereColumn('lookbook_tasks.product_id', 'products.id')
                    ->where('lookbook_tasks.user_id', $user->id)
                    ->where(fn ($w) => $w
                        ->where(fn ($p) => $p->where('status', LookbookTask::STATUS_PROCESSING)->where('updated_at', '>', $staleBefore))
                        ->orWhere(fn ($f) => $f->where('status', LookbookTask::STATUS_FAILED)->where('created_at', '>=', now()->startOfDay()))))
                ->addSelect(['products.*', 'last_lookbook_at' => LookbookTask::query()
                    ->select('finished_at')
                    ->whereColumn('product_id', 'products.id')
                    ->where('user_id', $user->id)
                    ->where('status', LookbookTask::STATUS_COMPLETED)
                    ->latest('finished_at')
                    ->limit(1)])
                ->orderByRaw('last_lookbook_at IS NOT NULL')
                ->orderBy('last_lookbook_at')
                ->orderBy('products.id')
                ->first();
            if (! $product) {
                return null;
            }

            // Task cũ của shop với sản phẩm này bị bỏ dở quá hạn -> lỗi, để lịch sử không còn task processing treo
            LookbookTask::query()
                ->where('product_id', $product->id)
                ->where('user_id', $user->id)
                ->where('status', LookbookTask::STATUS_PROCESSING)
                ->update([
                    'status' => LookbookTask::STATUS_FAILED,
                    'error_code' => 'TASK_EXPIRED',
                    'error_message' => 'Quá '.LookbookTask::STALE_MINUTES.' phút không báo kết quả.',
                    'finished_at' => now(),
                ]);

            $task = LookbookTask::query()->create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'worker_id' => $worker->id,
                'status' => LookbookTask::STATUS_PROCESSING,
                'image_field' => $imageField,
            ]);
            $this->audit->log('lookbook_task.start', $task, ['product_id' => $product->id, 'worker_id' => $worker->id], $user->id);

            return $task;
        });
    }

    /** Outfit (id bên OOTD của shop $userId) đã dùng trong các lookbook xong của shop với sản phẩm, để không dùng lại. */
    public function usedOutfitIds(int $productId, int $userId): array
    {
        return LookbookTask::query()
            ->where('product_id', $productId)
            ->where('user_id', $userId)
            ->where('status', LookbookTask::STATUS_COMPLETED)
            ->pluck('outfit_ids')
            ->flatten()
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Lưu ảnh (giữ file gốc + thumbnail), tạo bài chờ duyệt (source lookbook, gắn sản phẩm), task -> completed.
     * Gọi lại cho task đã xong (skill gửi lặp vì mất mạng) -> trả bài đã tạo, không tạo bài thứ 2.
     *
     * @param  UploadedFile[]  $files  theo thứ tự, ảnh đầu là ảnh bìa
     * @param  array{title: string, content?: ?string, hashtags?: ?array, outfit_ids?: ?array}  $data
     */
    public function complete(LookbookTask $task, array $files, array $data): SocialPost
    {
        if ($task->status === LookbookTask::STATUS_COMPLETED && $task->post) {
            return $task->post;
        }
        $this->assertProcessing($task);

        $stored = [];
        foreach ($files as $file) {
            $directory = MediaStorage::datedDirectory("social-posts/{$task->user_id}/lookbook");
            $path = $this->media->storeUpload($file, $directory);
            [$thumb] = $this->media->storeOptimizedImage($file, $directory, [self::THUMB_MAX_SIDE]);
            $stored[] = ['image_path' => $path, 'thumb_path' => $thumb];
        }

        return DB::transaction(function () use ($task, $stored, $data) {
            $task = LookbookTask::query()->lockForUpdate()->findOrFail($task->id);
            $this->assertProcessing($task);

            $post = SocialPost::query()->create([
                'user_id' => $task->user_id,
                'type' => SocialPost::TYPE_PHOTO,
                'status' => SocialPostStatus::PendingReview,
                'source' => SocialPost::SOURCE_LOOKBOOK,
                'product_id' => $task->product_id,
                'ai_content' => false,
                'idempotency_key' => 'lookbook-task-'.$task->id,
                'title' => trim($data['title']),
                'content' => $data['content'] ?? null,
                'hashtags' => SocialPost::normalizeHashtags($data['hashtags'] ?? []) ?: null,
            ]);
            foreach ($stored as $i => $image) {
                $post->images()->create([...$image, 'position' => $i + 1]);
            }

            $task->update([
                'status' => LookbookTask::STATUS_COMPLETED,
                'social_post_id' => $post->id,
                'outfit_ids' => array_values(array_map('intval', $data['outfit_ids'] ?? [])),
                'finished_at' => now(),
            ]);
            $this->audit->log('lookbook_task.complete', $task, ['social_post_id' => $post->id, 'images' => count($stored)], $task->user_id);

            return $post;
        });
    }

    /** Skill báo lỗi. Gọi lặp cho task đã lỗi -> giữ nguyên. Task đã xong -> 409. */
    public function fail(LookbookTask $task, string $code, ?string $message): LookbookTask
    {
        return DB::transaction(function () use ($task, $code, $message) {
            $task = LookbookTask::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status === LookbookTask::STATUS_FAILED) {
                return $task;
            }
            $this->assertProcessing($task);

            $task->update([
                'status' => LookbookTask::STATUS_FAILED,
                'error_code' => $code,
                'error_message' => $message !== null ? mb_substr($message, 0, 500) : null,
                'finished_at' => now(),
            ]);
            $this->audit->log('lookbook_task.fail', $task, ['code' => $code], $task->user_id);

            return $task;
        });
    }

    private function assertProcessing(LookbookTask $task): void
    {
        if (! $task->isProcessing()) {
            throw new BusinessException('LOOKBOOK_TASK_FINISHED', "Task lookbook #{$task->id} đã kết thúc ({$task->status}).", 409);
        }
    }
}
