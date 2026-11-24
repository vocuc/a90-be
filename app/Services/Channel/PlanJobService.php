<?php

namespace App\Services\Channel;

use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\ContentPlan;
use App\Models\PlanJob;
use App\Models\Worker;
use App\Models\WorkerJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lập kế hoạch kênh bằng worker (a90-fe note/xay-kenh-tiktok/worker-lap-ke-hoach.txt, người dùng yêu cầu 2026-11-21):
 * chủ shop bấm "Lập kế hoạch mới" ở tab Kế hoạch -> 1 việc plan trong worker_jobs; worker điều phối của shop nhận việc
 * qua /api/v1/worker-jobs, chạy skill Claude /lap-ke-hoach <id kênh> (skill tự lấy context + gửi kỳ qua API
 * channel-plan), xong gửi báo cáo ngắn -> tab Kế hoạch hiện báo cáo.
 */
class PlanJobService
{
    public static function hasWorker(int $userId): bool
    {
        return Worker::query()->assignedTo($userId, Worker::TYPE_PLAN)->exists();
    }

    /** Việc gần nhất của kênh (tab Kế hoạch hiện trạng thái). */
    public function latest(Channel $channel): ?PlanJob
    {
        return PlanJob::query()->where('channel_id', $channel->id)->latest('id')->first();
    }

    /**
     * Chủ shop bấm "Làm lại" 1 bài (người dùng 2026-11-22: bấm làm lại mà điều phối không nhận việc): tạo việc plan chế độ
     * LÀM LẠI (payload redo) để worker điều phối chạy /lap-ke-hoach --lam-lai (chỉ soạn lại các bài đang đánh dấu). Kênh đã
     * có việc plan đang chờ (chưa worker nhận) thì dùng chung việc đó (lấy context lúc nhận nên thấy cả bài vừa đánh dấu).
     * Shop chưa gắn worker điều phối: null (bài vẫn đánh dấu, lượt lập kế hoạch sau làm lại).
     */
    public function queueRedo(Channel $channel): ?PlanJob
    {
        if (! self::hasWorker($channel->user_id)) {
            return null;
        }

        return DB::transaction(function () use ($channel) {
            Channel::query()->lockForUpdate()->find($channel->id);
            $queued = PlanJob::query()->where('channel_id', $channel->id)->where('status', WorkerJob::QUEUED)->latest('id')->first();

            return $queued ?? PlanJob::query()->create([
                'user_id' => $channel->user_id,
                'channel_id' => $channel->id,
                'status' => WorkerJob::QUEUED,
                'payload' => ['note' => null, 'plan_id' => null, 'redo' => true],
            ]);
        });
    }

    /**
     * Kênh không còn bài nào chờ làm lại (huỷ / xoá kỳ, bỏ đánh dấu, người dùng 2026-11-25: "hủy kỳ xóa kỳ rồi vẫn hiện
     * Đang chờ worker nhận việc làm lại bài") -> việc LÀM LẠI còn CHỜ (chưa worker nhận) thành superseded. Việc đang chạy để
     * worker tự xong (context rỗng -> báo không còn bài). Trả số việc đã huỷ.
     */
    public function dropIdleRedo(Channel $channel): int
    {
        if (app(ChannelPlanService::class)->hasRedoRequests($channel)) {
            return 0;
        }

        return PlanJob::query()->where('channel_id', $channel->id)->where('status', WorkerJob::QUEUED)
            ->get()->filter(fn (PlanJob $j) => ($j->payload['redo'] ?? false) && empty($j->payload['plan_id']))
            ->each(fn (PlanJob $j) => $j->update(['status' => WorkerJob::SUPERSEDED, 'lease_expires_at' => null, 'finished_at' => now()]))
            ->count();
    }

    /** $fill: TẠO BỔ SUNG cho kỳ này (chờ duyệt / đã duyệt, còn slot thiếu bài) thay vì lập kỳ mới. */
    public function create(Channel $channel, ?string $note, ?ContentPlan $fill = null): PlanJob
    {
        if (! self::hasWorker($channel->user_id)) {
            throw new BusinessException('PLAN_WORKER_REQUIRED', 'Shop chưa được gắn worker điều phối để lập kế hoạch, liên hệ admin A51 để gắn.', 409);
        }
        $missing = array_intersect($channel->missing(), ['worker', 'time_slots']);
        if ($missing) {
            throw new BusinessException('CHANNEL_NOT_READY', in_array('worker', $missing, true)
                ? 'Kênh chưa chọn worker đăng bài, vào tab Cấu hình để chọn rồi lập kế hoạch.'
                : 'Kênh chưa có khung giờ đăng, vào tab Cấu hình để thêm rồi lập kế hoạch.', 409);
        }
        if (! $channel->topics()->where('status', \App\Models\ChannelTopic::ACTIVE)->exists()) {
            throw new BusinessException('TOPICS_REQUIRED', 'Kênh chưa có chủ đề đang bật, vào tab Chủ đề để xây dựng chủ đề trước.', 409);
        }
        if ($fill) {
            if (! in_array($fill->status, [ContentPlan::DRAFT, ContentPlan::APPROVED], true)) {
                throw new BusinessException('PLAN_CLOSED', 'Chỉ bổ sung được kỳ chờ duyệt hoặc đã duyệt.', 409);
            }
            $plans = app(ChannelPlanService::class);
            if (! $plans->missingSlots($fill)) {
                // Báo đúng lý do: khung giờ hết chỗ (tăng số bài / ngày mà khung giờ hẹp) hay đã qua giờ đăng
                throw new BusinessException('PLAN_FULL', $plans->fillBlockedReason($fill), 409);
            }
        } elseif ($channel->plans()->where('status', ContentPlan::DRAFT)->exists()) {
            throw new BusinessException('PLAN_DRAFT_PENDING', 'Kỳ trước đang chờ duyệt, duyệt hoặc huỷ kỳ đó rồi lập kỳ mới.', 409);
        }

        return DB::transaction(function () use ($channel, $note, $fill) {
            Channel::query()->lockForUpdate()->find($channel->id);
            if (PlanJob::query()->where('channel_id', $channel->id)->whereIn('status', [WorkerJob::QUEUED, WorkerJob::PROCESSING])->exists()) {
                throw new BusinessException('PLAN_JOB_RUNNING', 'Kênh đang có lượt lập kế hoạch chưa xong, chờ xong rồi bấm lại.', 409);
            }

            return PlanJob::query()->create([
                'user_id' => $channel->user_id,
                'channel_id' => $channel->id,
                'status' => WorkerJob::QUEUED,
                'payload' => ['note' => trim((string) $note) ?: null, 'plan_id' => $fill?->id],
            ]);
        });
    }

    /** Việc cũ nhất của các shop được gắn (việc đang làm mà worker im lặng quá hạn cũng giao lại). */
    public function claim(Worker $worker): ?PlanJob
    {
        $worker->assertType(Worker::TYPE_PLAN);

        return DB::transaction(function () use ($worker) {
            $job = PlanJob::query()
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
                'lease_expires_at' => now()->addMinutes(PlanJob::LEASE_MINUTES),
            ]);

            return $job;
        });
    }

    /** Dữ liệu gửi worker: skill /lap-ke-hoach tự lấy context kênh qua API channel-plan. */
    public function request(PlanJob $job): array
    {
        $channel = $job->channel()->firstOrFail();

        return [
            'channel_id' => $channel->id,
            'channel_name' => $channel->name,
            'note' => $job->payload['note'] ?? null,
            // Có: TẠO BỔ SUNG cho kỳ này (skill chạy /lap-ke-hoach <id kênh> --bo-sung <plan_id>)
            'plan_id' => $job->payload['plan_id'] ?? null,
            // Chế độ LÀM LẠI: skill chạy /lap-ke-hoach <id kênh> --lam-lai (chỉ làm lại bài chủ shop đánh dấu)
            'redo' => (bool) ($job->payload['redo'] ?? false),
        ];
    }

    /** Worker xong: lưu báo cáo của Claude + kỳ kế hoạch mới nhất của kênh tạo / gửi trong lượt này (nếu có). */
    public function complete(Worker $worker, int $jobId, ?string $summary): array
    {
        return DB::transaction(function () use ($worker, $jobId, $summary) {
            $job = $this->lockAssigned($worker, $jobId);
            $fillId = $job->payload['plan_id'] ?? null;
            $plan = $job->channel()->firstOrFail()->plans()
                ->when($fillId, fn ($q) => $q->whereKey($fillId), fn ($q) => $q->where('updated_at', '>=', $job->claimed_at ?? $job->created_at))
                ->whereIn('status', [ContentPlan::DRAFT, ContentPlan::APPROVED])
                ->latest('id')->first(['id', 'status']);
            $result = [
                'summary' => Str::limit(trim((string) $summary), PlanJob::SUMMARY_MAX, '…') ?: null,
                'plan_id' => $plan?->id,
                'plan_status' => $plan?->status,
            ];
            $job->update([
                'status' => WorkerJob::COMPLETED,
                'result' => $result,
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
                'error_message' => Str::limit((string) ($errorMessage ?: 'Worker không lập được kế hoạch.'), 490, ''),
            ]);
        });
    }

    private function lockAssigned(Worker $worker, int $jobId): PlanJob
    {
        $job = PlanJob::query()->lockForUpdate()->find($jobId);
        if (! $job || $job->status !== WorkerJob::PROCESSING || $job->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }
}
