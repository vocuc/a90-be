<?php

namespace App\Services\Channel;

use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\ChannelTopic;
use App\Models\TopicJob;
use App\Models\Worker;
use App\Models\WorkerJob;
use App\Support\PostSamples;
use App\Support\ProductTags;
use App\Support\VietnamHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Xây dựng chủ đề tự động cho kênh (a90-fe note/xay-kenh-tiktok/worker-lap-chu-de.txt, người dùng chốt 2026-11-21):
 * chủ shop bấm nút ở tab Chủ đề (nhập số chủ đề + ghi chú) -> 1 việc topic trong worker_jobs; worker lập chủ đề của shop
 * nhận việc qua /api/v1/worker-jobs, gọi skill Claude /lap-chu-de, trả danh sách chủ đề -> A51 lưu luôn (bật sẵn,
 * nguồn ai), bỏ qua chủ đề trùng tên.
 */
class TopicJobService
{
    /** Ngày lễ trong khoảng này (tính từ hôm nay) gửi kèm cho Claude. */
    public const HOLIDAY_DAYS = 120;

    public function __construct(private ChannelTopicService $topics, private ProductTagService $productTags) {}

    public static function hasWorker(int $userId): bool
    {
        return Worker::query()->assignedTo($userId, Worker::TYPE_TOPIC)->exists();
    }

    /** Việc gần nhất của kênh (tab Chủ đề hiện trạng thái). */
    public function latest(Channel $channel): ?TopicJob
    {
        return TopicJob::query()->where('channel_id', $channel->id)->latest('id')->first();
    }

    public function create(Channel $channel, int $count, ?string $note): TopicJob
    {
        if (! self::hasWorker($channel->user_id)) {
            throw new BusinessException('TOPIC_WORKER_REQUIRED', 'Shop chưa được gắn worker lập chủ đề, liên hệ admin A51 để gắn.', 409);
        }

        return DB::transaction(function () use ($channel, $count, $note) {
            Channel::query()->lockForUpdate()->find($channel->id);
            if (TopicJob::query()->where('channel_id', $channel->id)->whereIn('status', [WorkerJob::QUEUED, WorkerJob::PROCESSING])->exists()) {
                throw new BusinessException('TOPIC_JOB_RUNNING', 'Kênh đang có lượt xây dựng chủ đề chưa xong, chờ xong rồi bấm lại.', 409);
            }

            return TopicJob::query()->create([
                'user_id' => $channel->user_id,
                'channel_id' => $channel->id,
                'status' => WorkerJob::QUEUED,
                'payload' => ['count' => $count, 'note' => trim((string) $note) ?: null],
            ]);
        });
    }

    /**
     * Worker lập chủ đề nhận việc cũ nhất của các shop được gắn (việc đang làm mà worker im lặng quá hạn cũng giao lại).
     * SKIP LOCKED để gọi song song không nhận trùng.
     */
    public function claim(Worker $worker): ?TopicJob
    {
        $worker->assertType(Worker::TYPE_TOPIC);

        return DB::transaction(function () use ($worker) {
            $job = TopicJob::query()
                ->whereIn('user_id', DB::table('worker_user')->select('user_id')->where('worker_id', $worker->id))
                ->where(fn ($q) => $q->where('status', WorkerJob::QUEUED)
                    ->orWhere(fn ($q) => $q->where('status', WorkerJob::PROCESSING)->where('lease_expires_at', '<', now())))
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();
            if (! $job) {
                return null;
            }
            $job->update([
                'status' => WorkerJob::PROCESSING,
                'worker_id' => $worker->id,
                'attempts' => $job->attempts + 1,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addMinutes(TopicJob::LEASE_MINUTES),
            ]);

            return $job;
        });
    }

    /** Dữ liệu gửi worker (skill /lap-chu-de đọc). */
    public function request(TopicJob $job): array
    {
        $channel = $job->channel()->with('user.creatorProfile')->firstOrFail();
        $lineup = $channel->approvedLineup();
        $keys = $lineup ? $lineup->sampleKeys() : array_keys(PostSamples::enabled());
        $today = CarbonImmutable::today();

        return [
            'count' => (int) ($job->payload['count'] ?? TopicJob::COUNT_DEFAULT),
            'note' => $job->payload['note'] ?? null,
            'today' => $today->toDateString(),
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'shop' => $channel->user?->creatorProfile?->shop_name ?? $channel->user?->name,
                'requirements' => $channel->aiSettings()['requirements'],
            ],
            'persona' => $channel->persona ?? (object) [],
            // Loại bài kênh dùng (chủ đề dùng chung mọi loại bài)
            'post_samples' => collect($keys)->filter(fn ($k) => PostSamples::isEnabled($k))
                ->map(fn ($k) => ['key' => $k, 'name' => PostSamples::get($k)['name']])->values()->all(),
            'holidays' => VietnamHolidays::between($today, $today->addDays(self::HOLIDAY_DAYS)),
            // Chủ đề đã có (kể cả đang tắt): không tạo trùng / gần trùng
            'existing_topics' => array_map(fn ($t) => ['name' => $t['name'], 'note' => $t['note'], 'status' => $t['status']],
                $this->topics->listFor($channel)),
            // Tham khảo khi viết ghi chú chủ đề (chủ đề không còn bộ lọc tag): giá trị tag + số sản phẩm đã gắn theo từng giá trị
            'tag_options' => ProductTags::options(),
            'product_tags' => $this->productTags->summary($channel->user),
        ];
    }

    /**
     * Worker trả chủ đề: [{name, note?}] (note: yêu cầu / định hướng cho lệnh lập kế hoạch, tối đa ChannelTopic::NOTE_MAX). Chủ đề hợp lệ, chưa có (không phân biệt hoa thường) -> tạo (nguồn ai, bật sẵn).
     *
     * @return array{created: string[], skipped: string[], errors: array<int, array{name: mixed, errors: string[]}>}
     */
    public function complete(Worker $worker, int $jobId, array $topics): array
    {
        return DB::transaction(function () use ($worker, $jobId, $topics) {
            $job = $this->lockAssigned($worker, $jobId);
            $channel = $job->channel()->firstOrFail();
            $created = [];
            $skipped = [];
            $errors = [];
            foreach ($topics as $row) {
                $name = ChannelTopic::cleanName(is_array($row) ? ($row['name'] ?? '') : '');
                if ($name === '' || mb_strlen($name) > ChannelTopic::NAME_MAX) {
                    $errors[] = ['name' => $row['name'] ?? null, 'errors' => ['name bắt buộc, tối đa '.ChannelTopic::NAME_MAX.' ký tự.']];

                    continue;
                }
                $note = ChannelTopic::cleanNote(is_string($row['note'] ?? null) ? $row['note'] : null);
                if ($note !== null && mb_strlen($note) > ChannelTopic::NOTE_MAX) {
                    $note = mb_substr($note, 0, ChannelTopic::NOTE_MAX);
                }
                if ($channel->topics()->where('name', $name)->exists()) {
                    $skipped[] = $name;

                    continue;
                }
                $channel->topics()->create(['name' => $name, 'note' => $note, 'source' => ProductTags::SOURCE_AI, 'status' => ChannelTopic::ACTIVE]);
                $created[] = $name;
            }
            $result = ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
            $job->update([
                'status' => WorkerJob::COMPLETED,
                'result' => ['created' => count($created), 'skipped' => count($skipped), 'errors' => count($errors)],
                'lease_expires_at' => null,
                'finished_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            return $result;
        });
    }

    public function fail(Worker $worker, int $jobId, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $jobId, $errorCode, $errorMessage) {
            $this->lockAssigned($worker, $jobId)->update([
                'status' => WorkerJob::FAILED,
                'lease_expires_at' => null,
                'finished_at' => now(),
                'error_code' => $errorCode,
                'error_message' => Str::limit((string) ($errorMessage ?: 'Worker không lập được chủ đề.'), 490, ''),
            ]);
        });
    }

    private function lockAssigned(Worker $worker, int $jobId): TopicJob
    {
        $job = TopicJob::query()->lockForUpdate()->find($jobId);
        if (! $job || $job->status !== WorkerJob::PROCESSING || $job->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }
}
