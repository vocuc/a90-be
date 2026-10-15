<?php

namespace App\Services\TikTok;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use App\Models\TikTokAccount;
use App\Models\TikTokPost;
use App\Models\TikTokPublishJob;
use App\Models\User;
use App\Models\Worker;
use App\Services\AuditLogger;
use App\Services\MediaStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Đăng bài TikTok qua worker (note/dang-tiktok-qua-worker.txt ở repo frontend), không dùng API TikTok:
 * bấm đăng -> lưu chỉnh sửa, tạo job, bài sang queued. Worker giữ tài khoản TikTok của A51 nhận job (publishing),
 * lưu nháp trên TikTok Studio rồi báo completed (published). Lỗi / quá hạn không giao lại: bài về pending_review
 * với một thông báo chung, người dùng tự bấm đăng lại. Job chờ không hết hạn.
 */
class TikTokPublishJobService
{
    public const FAILED_ERROR = ['code' => 'TIKTOK_PUBLISH_FAILED', 'message' => 'Đăng thất bại, vui lòng thử lại.'];

    public function __construct(
        private TikTokPostService $posts,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{title?: ?string, content?: ?string, hashtags?: ?array, images: array<int, array>}  $data
     */
    public function publish(User $user, TikTokPost $post, array $data): TikTokPost
    {
        $this->posts->assertEditable($post);

        $hashtags = TikTokPost::normalizeHashtags($data['hashtags'] ?? []);
        if (mb_strlen(TikTokPost::description($data['content'] ?? null, $hashtags)) > TikTokPost::CONTENT_MAX) {
            throw ValidationException::withMessages(['content' => 'Nội dung kèm hashtag tối đa '.TikTokPost::CONTENT_MAX.' ký tự.']);
        }

        $images = $this->resolveImages($user, $post, $data['images']);

        return DB::transaction(function () use ($user, $post, $data, $hashtags, $images) {
            $post = TikTokPost::query()->lockForUpdate()->findOrFail($post->id);
            // Bấm đăng 2 lần: lần sau gặp bài đã queued -> 409, không tạo job thứ 2
            $this->posts->assertEditable($post);

            $post->update([
                'title' => trim((string) ($data['title'] ?? '')),
                'content' => $data['content'] ?? '',
                'hashtags' => $hashtags,
                'status' => TikTokPostStatus::Queued,
                'last_error' => null,
                'tiktok_account_id' => null,
                'published_at' => null,
            ]);
            $this->syncImages($post, $images);
            $job = $post->publishJobs()->create(['status' => OutputStatus::Queued]);

            $this->audit->log('tiktok_post.publish', $post, ['job_id' => $job->id, 'images' => count($images)], $user->id);

            return $post;
        });
    }

    /**
     * Nhận job chờ cũ nhất. Chỉ worker được gắn tài khoản TikTok đang bật mới nhận được; bài vào tài khoản của worker đó.
     * SKIP LOCKED để nhiều worker gọi cùng lúc không nhận trùng.
     */
    public function claim(Worker $worker): ?TikTokPublishJob
    {
        $account = TikTokAccount::query()->where('worker_id', $worker->id)->first();
        if (! $account?->is_active) {
            throw new BusinessException('TIKTOK_ACCOUNT_NOT_ASSIGNED', 'Worker chưa được gắn tài khoản TikTok hoặc tài khoản đang tắt.', 409);
        }

        return DB::transaction(function () use ($worker, $account) {
            $job = TikTokPublishJob::query()
                ->where('status', OutputStatus::Queued)
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if (! $job) {
                return null;
            }

            $job->update([
                'status' => OutputStatus::Processing,
                'worker_id' => $worker->id,
                'tiktok_account_id' => $account->id,
                'lease_expires_at' => now()->addMinutes(config('ai90.worker.publish_lease_minutes')),
            ]);
            TikTokPost::query()->whereKey($job->tiktok_post_id)->update([
                'status' => TikTokPostStatus::Publishing,
                'tiktok_account_id' => $account->id,
            ]);

            return $job;
        });
    }

    /** Dữ liệu gửi worker. Link ảnh ký số tạo lúc nhận job (job có thể đã chờ lâu). */
    public function request(TikTokPublishJob $job): array
    {
        $post = $job->post()->with('images.output')->firstOrFail();
        $hashtags = $post->hashtags ?? [];

        return [
            'post_id' => $post->id,
            'title' => $post->title,
            'content' => (string) $post->content,
            'hashtags' => $hashtags,
            // Nội dung + dòng hashtag, điền thẳng vào ô mô tả của TikTok
            'description' => TikTokPost::description($post->content, $hashtags),
            // Theo thứ tự, ảnh đầu là ảnh bìa
            'images' => $post->images->map(fn ($image) => $this->media->url($image->output->image_path))->values()->all(),
            'account' => $job->tiktokAccount?->only(['id', 'name', 'username']),
        ];
    }

    /** Worker đã lưu nháp xong. */
    public function complete(Worker $worker, TikTokPublishJob $job): void
    {
        DB::transaction(function () use ($worker, $job) {
            $job = $this->lockAssigned($worker, $job);
            $job->update([
                'status' => OutputStatus::Completed,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ]);

            TikTokPost::query()
                ->whereKey($job->tiktok_post_id)
                ->where('status', TikTokPostStatus::Publishing)
                ->update(['status' => TikTokPostStatus::Published, 'published_at' => now(), 'last_error' => null]);
        });
    }

    /** Worker báo lỗi: không giao lại, bài về chờ gửi. Mã lỗi chi tiết chỉ lưu ở job cho admin. */
    public function fail(Worker $worker, TikTokPublishJob $job, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $job, $errorCode, $errorMessage) {
            $this->failJob($this->lockAssigned($worker, $job), $errorCode, $errorMessage ?: 'Worker không đăng được bài.');
        });
    }

    /** Job worker giữ quá hạn: thất bại luôn (không giao lại vì có thể worker đã lưu nháp một phần). */
    public function releaseExpiredLeases(): int
    {
        $ids = TikTokPublishJob::query()
            ->where('status', OutputStatus::Processing)
            ->where('lease_expires_at', '<', now())
            ->pluck('id');

        $failed = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$failed) {
                $job = TikTokPublishJob::query()->lockForUpdate()->find($id);
                if (! $job || $job->status !== OutputStatus::Processing || $job->lease_expires_at?->isFuture()) {
                    return;
                }
                $this->failJob($job, 'WORKER_TIMEOUT', 'Worker không trả kết quả trong thời gian cho phép.');
                $failed++;
            });
        }

        return $failed;
    }

    private function failJob(TikTokPublishJob $job, string $errorCode, string $errorMessage): void
    {
        $job->update([
            'status' => OutputStatus::Failed,
            'lease_expires_at' => null,
            'finished_at' => now(),
            'error_code' => $errorCode,
            'error_message' => Str::limit($errorMessage, 490),
        ]);

        TikTokPost::query()
            ->whereKey($job->tiktok_post_id)
            ->whereIn('status', [TikTokPostStatus::Queued, TikTokPostStatus::Publishing])
            ->update(['status' => TikTokPostStatus::PendingReview, 'last_error' => json_encode(self::FAILED_ERROR, JSON_UNESCAPED_UNICODE)]);
    }

    private function lockAssigned(Worker $worker, TikTokPublishJob $job): TikTokPublishJob
    {
        $job = TikTokPublishJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($job->status !== OutputStatus::Processing || $job->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }

    /**
     * Thứ tự cuối cùng: { image_id } (ảnh đang có trong bài) hoặc { generation_id, output_id } (ảnh có sẵn vừa thêm).
     *
     * @return array<int, array{image_id: ?int, output: GenerationOutput}> theo thứ tự (image_id null: ảnh mới thêm)
     */
    private function resolveImages(User $user, TikTokPost $post, array $items): array
    {
        $current = $post->images()->with('output')->get()->keyBy('id');
        $newRefs = [];
        $errors = [];
        $resolved = [];

        foreach ($items as $i => $item) {
            if (isset($item['image_id'])) {
                $image = $current->get((int) $item['image_id']);
                if (! $image) {
                    $errors["images.{$i}"] = 'Ảnh không còn trong bài đăng.';

                    continue;
                }
                $resolved[$i] = ['image_id' => $image->id, 'output' => $image->output];
            } else {
                $newRefs[$i] = ['generation_id' => (int) $item['generation_id'], 'output_id' => (int) $item['output_id']];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($newRefs ? $this->posts->resolveOwnedOutputs($user, $newRefs, 'images') : [] as $i => $output) {
            $resolved[$i] = ['image_id' => null, 'output' => $output];
        }
        ksort($resolved);

        $outputIds = array_map(fn ($r) => $r['output']->id, $resolved);
        if (count($outputIds) !== count(array_unique($outputIds))) {
            throw ValidationException::withMessages(['images' => 'Có ảnh bị trùng trong bài đăng.']);
        }
        if (collect($resolved)->contains(fn ($r) => $r['output']->status !== OutputStatus::Completed)) {
            throw new BusinessException('IMAGES_NOT_READY', 'Còn ảnh đang tạo hoặc bị lỗi. Hãy bỏ ảnh đó khỏi bài trước khi đăng.', 409);
        }

        return array_values($resolved);
    }

    /** @param  array<int, array{image_id: ?int, output: GenerationOutput}>  $images */
    private function syncImages(TikTokPost $post, array $images): void
    {
        $keep = array_filter(array_column($images, 'image_id'));
        $post->images()->whereNotIn('id', $keep ?: [0])->delete();

        foreach ($images as $i => ['image_id' => $imageId, 'output' => $output]) {
            $imageId
                ? $post->images()->whereKey($imageId)->update(['position' => $i + 1])
                : $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
        }
    }
}
