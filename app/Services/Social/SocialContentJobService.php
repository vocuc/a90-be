<?php

namespace App\Services\Social;

use App\Enums\OutputStatus;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\SocialContentJob;
use App\Models\SocialPost;
use App\Models\Worker;
use App\Services\MediaStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Job viết tiêu đề / nội dung / hashtag cho worker nội bộ (1 job / bài), giao việc giống job ảnh:
 * lease, giao lại khi quá hạn, hết lượt thì dùng nội dung mặc định. Bài luôn sang pending_review khi job chốt.
 * Chỉ dùng khi bật ai90.social.ai_content (SOCIAL_AI_CONTENT).
 */
class SocialContentJobService
{
    public function __construct(private MediaStorage $media) {}

    public function claim(Worker $worker): ?SocialContentJob
    {
        return DB::transaction(function () use ($worker) {
            $job = SocialContentJob::query()
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
                'attempts' => $job->attempts + 1,
                'lease_expires_at' => now()->addMinutes(config('ai90.worker.content_lease_minutes')),
            ]);

            return $job;
        });
    }

    /** Dữ liệu gửi worker: các mẫu của bài (tên, mô tả, ngành hàng, hashtag) + link ảnh đã tạo xong. */
    public function request(SocialContentJob $job): array
    {
        $post = $job->post()->with(['images.output', 'images.generation.creative.category', 'images.generation.creative.hashtags'])->firstOrFail();

        $creatives = $post->images
            ->map(fn ($image) => $image->generation?->creative)
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($creative) => [
                'title' => $creative->title,
                'description' => $creative->description,
                'category' => $creative->category?->name,
                'hashtags' => $creative->hashtags->pluck('name')->values()->all(),
            ]);

        $images = $post->images
            ->filter(fn ($image) => $image->output?->status === OutputStatus::Completed)
            ->map(fn ($image) => $this->media->url($image->output->image_path))
            ->values();

        return [
            'language' => 'vi',
            'limits' => [
                'title_max' => SocialPost::TITLE_MAX,
                'content_max' => SocialPost::CONTENT_MAX,
                'hashtags_max' => SocialPost::MAX_HASHTAGS,
            ],
            'creatives' => $creatives->all(),
            'images' => $images->all(),
        ];
    }

    /** @param  string[]  $hashtags */
    public function complete(Worker $worker, SocialContentJob $job, string $title, ?string $content, array $hashtags): void
    {
        DB::transaction(function () use ($worker, $job, $title, $content, $hashtags) {
            $job = $this->lockAssigned($worker, $job);

            // Nội dung không chứa hashtag: tách #tag trong nội dung sang danh sách hashtag
            preg_match_all('/#([\p{L}\p{N}_]+)/u', (string) $content, $inline);
            $content = trim(preg_replace('/[ \t]*#[\p{L}\p{N}_]+/u', '', (string) $content));

            $title = Str::limit(trim(preg_replace('/\s+/u', ' ', $title)), SocialPost::TITLE_MAX, '');
            $this->finish($job, [
                'title' => $title !== '' ? $title : null,
                'content' => mb_substr($content, 0, SocialPost::CONTENT_MAX),
                'hashtags' => SocialPost::normalizeHashtags([...$hashtags, ...$inline[1]]),
            ], OutputStatus::Completed);
        });
    }

    /** Worker báo lỗi: còn lượt thì giao lại, hết lượt thì dùng nội dung mặc định. */
    public function fail(Worker $worker, SocialContentJob $job, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $job, $errorCode, $errorMessage) {
            $job = $this->lockAssigned($worker, $job);
            $this->retryOrFallback($job, $errorCode, $errorMessage ?: 'Worker không viết được nội dung.');
        });
    }

    /** @return array{requeued: int, failed: int} */
    public function releaseExpiredLeases(): array
    {
        $ids = SocialContentJob::query()
            ->where('status', OutputStatus::Processing)
            ->where('lease_expires_at', '<', now())
            ->pluck('id');

        $result = ['requeued' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$result) {
                $job = SocialContentJob::query()->lockForUpdate()->find($id);
                if (! $job || $job->status !== OutputStatus::Processing || $job->lease_expires_at?->isFuture()) {
                    return;
                }
                $result[$this->retryOrFallback($job, 'TIMEOUT', 'Worker không trả kết quả sau '.$job->attempts.' lần nhận.')]++;
            });
        }

        return $result;
    }

    /** Job chờ quá lâu mà không worker nào nhận: dùng nội dung mặc định để bài không kẹt ở generating. */
    public function failStuckQueued(): int
    {
        $ids = SocialContentJob::query()
            ->where('status', OutputStatus::Queued)
            ->where('updated_at', '<', now()->subMinutes(config('ai90.generation.stuck_queued_minutes')))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $job = SocialContentJob::query()->lockForUpdate()->find($id);
                if ($job?->status === OutputStatus::Queued) {
                    $this->fallback($job, 'TIMEOUT', 'Quá thời gian chờ worker viết nội dung.');
                }
            });
        }

        return $ids->count();
    }

    /** @return 'requeued'|'failed' */
    private function retryOrFallback(SocialContentJob $job, string $errorCode, string $errorMessage): string
    {
        if ($job->attempts < config('ai90.worker.max_attempts')) {
            $job->update([
                'status' => OutputStatus::Queued,
                'worker_id' => null,
                'lease_expires_at' => null,
                'error_code' => $errorCode,
                'error_message' => Str::limit($errorMessage, 490),
            ]);

            return 'requeued';
        }

        $this->fallback($job, $errorCode, $errorMessage);

        return 'failed';
    }

    /** Nội dung mặc định từ các mẫu của bài; người dùng tự sửa ở bước duyệt. */
    private function fallback(SocialContentJob $job, string $errorCode, string $errorMessage): void
    {
        $job->forceFill(['error_code' => $errorCode, 'error_message' => Str::limit($errorMessage, 490)]);
        $this->finish($job, $job->post->defaultContent(), OutputStatus::Failed);
    }

    /** @param  array{title: ?string, content: string, hashtags: string[]}  $content */
    private function finish(SocialContentJob $job, array $content, OutputStatus $status): void
    {
        $job->update([
            'status' => $status,
            'lease_expires_at' => null,
            'finished_at' => now(),
            ...($status === OutputStatus::Completed ? ['error_code' => null, 'error_message' => null] : []),
        ]);

        $post = SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id);
        if ($post->status !== SocialPostStatus::Generating) {
            return;
        }

        $post->update([
            'title' => $content['title'] ?? SocialPost::DEFAULT_TITLE,
            'content' => $content['content'],
            'hashtags' => $content['hashtags'],
            'status' => SocialPostStatus::PendingReview,
        ]);
    }

    private function lockAssigned(Worker $worker, SocialContentJob $job): SocialContentJob
    {
        $job = SocialContentJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($job->status !== OutputStatus::Processing || $job->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }
}
