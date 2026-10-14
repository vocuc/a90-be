<?php

namespace App\Services\TikTok;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Exceptions\BusinessException;
use App\Models\Generation;
use App\Models\TikTokContentJob;
use App\Models\TikTokPost;
use App\Models\Worker;
use App\Services\MediaStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Job viết tiêu đề / nội dung / hashtag cho worker nội bộ (1 job / bài), giao việc giống job ảnh:
 * lease, giao lại khi quá hạn, hết lượt thì dùng nội dung mặc định. Bài luôn sang pending_review khi job chốt.
 */
class TikTokContentJobService
{
    private const FALLBACK_TITLE = 'Ảnh mới từ A51';

    public function __construct(private MediaStorage $media) {}

    public function claim(Worker $worker): ?TikTokContentJob
    {
        return DB::transaction(function () use ($worker) {
            $job = TikTokContentJob::query()
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
    public function request(TikTokContentJob $job): array
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
                'title_max' => TikTokPost::TITLE_MAX,
                'content_max' => TikTokPost::CONTENT_MAX,
                'hashtags_max' => TikTokPost::MAX_HASHTAGS,
            ],
            'creatives' => $creatives->all(),
            'images' => $images->all(),
        ];
    }

    /** @param  string[]  $hashtags */
    public function complete(Worker $worker, TikTokContentJob $job, string $title, ?string $content, array $hashtags): void
    {
        DB::transaction(function () use ($worker, $job, $title, $content, $hashtags) {
            $job = $this->lockAssigned($worker, $job);

            // Nội dung không chứa hashtag: tách #tag trong nội dung sang danh sách hashtag
            preg_match_all('/#([\p{L}\p{N}_]+)/u', (string) $content, $inline);
            $content = trim(preg_replace('/[ \t]*#[\p{L}\p{N}_]+/u', '', (string) $content));

            $title = Str::limit(trim(preg_replace('/\s+/u', ' ', $title)), TikTokPost::TITLE_MAX, '');
            $this->finish($job, [
                'title' => $title !== '' ? $title : null,
                'content' => mb_substr($content, 0, TikTokPost::CONTENT_MAX),
                'hashtags' => TikTokPost::normalizeHashtags([...$hashtags, ...$inline[1]]),
            ], OutputStatus::Completed);
        });
    }

    /** Worker báo lỗi: còn lượt thì giao lại, hết lượt thì dùng nội dung mặc định. */
    public function fail(Worker $worker, TikTokContentJob $job, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $job, $errorCode, $errorMessage) {
            $job = $this->lockAssigned($worker, $job);
            $this->retryOrFallback($job, $errorCode, $errorMessage ?: 'Worker không viết được nội dung.');
        });
    }

    /** @return array{requeued: int, failed: int} */
    public function releaseExpiredLeases(): array
    {
        $ids = TikTokContentJob::query()
            ->where('status', OutputStatus::Processing)
            ->where('lease_expires_at', '<', now())
            ->pluck('id');

        $result = ['requeued' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$result) {
                $job = TikTokContentJob::query()->lockForUpdate()->find($id);
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
        $ids = TikTokContentJob::query()
            ->where('status', OutputStatus::Queued)
            ->where('updated_at', '<', now()->subMinutes(config('ai90.generation.stuck_queued_minutes')))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $job = TikTokContentJob::query()->lockForUpdate()->find($id);
                if ($job?->status === OutputStatus::Queued) {
                    $this->fallback($job, 'TIMEOUT', 'Quá thời gian chờ worker viết nội dung.');
                }
            });
        }

        return $ids->count();
    }

    /** @return 'requeued'|'failed' */
    private function retryOrFallback(TikTokContentJob $job, string $errorCode, string $errorMessage): string
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
    private function fallback(TikTokContentJob $job, string $errorCode, string $errorMessage): void
    {
        $generations = Generation::query()
            ->whereIn('id', $job->post->images()->pluck('generation_id'))
            ->with('creative.hashtags')
            ->get();
        $creatives = $generations->pluck('creative')->filter()->unique('id')->values();

        $job->forceFill(['error_code' => $errorCode, 'error_message' => Str::limit($errorMessage, 490)]);
        $this->finish($job, [
            'title' => $creatives->isNotEmpty() ? Str::limit($creatives->first()->title, TikTokPost::TITLE_MAX, '') : null,
            'content' => '',
            'hashtags' => TikTokPost::normalizeHashtags($creatives->flatMap(fn ($c) => $c->hashtags->pluck('name'))->all()),
        ], OutputStatus::Failed);
    }

    /** @param  array{title: ?string, content: string, hashtags: string[]}  $content */
    private function finish(TikTokContentJob $job, array $content, OutputStatus $status): void
    {
        $job->update([
            'status' => $status,
            'lease_expires_at' => null,
            'finished_at' => now(),
            ...($status === OutputStatus::Completed ? ['error_code' => null, 'error_message' => null] : []),
        ]);

        $post = TikTokPost::query()->lockForUpdate()->findOrFail($job->tiktok_post_id);
        if ($post->status !== TikTokPostStatus::Generating) {
            return;
        }

        $post->update([
            'title' => $content['title'] ?? self::FALLBACK_TITLE,
            'content' => $content['content'],
            'hashtags' => $content['hashtags'],
            'status' => TikTokPostStatus::PendingReview,
        ]);
    }

    private function lockAssigned(Worker $worker, TikTokContentJob $job): TikTokContentJob
    {
        $job = TikTokContentJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($job->status !== OutputStatus::Processing || $job->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }
}
