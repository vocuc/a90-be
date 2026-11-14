<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Worker;
use App\Services\Social\SocialPostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * "Lập lịch đăng bài" nhiều lô: mỗi lô 1 job chạy ngầm, tạo bài ảnh đã giao worker + giờ đăng
 * (SocialPostService::createScheduledFromBatch). Người dùng không phải chờ; bài hiện dần ở trang Bài đăng.
 */
class CreateScheduledBatchPost implements ShouldQueue
{
    use Queueable;

    // Tạo bài idempotent theo idempotencyKey nên thử lại an toàn
    public int $tries = 3;

    public int $backoff = 30;

    /** @param  string[]  $platforms */
    public function __construct(
        public int $userId,
        public int $batchId,
        public int $workerId,
        public array $platforms,
        public string $scheduledAt,
        public bool $aiContent,
        public string $idempotencyKey,
    ) {}

    public function handle(SocialPostService $posts): void
    {
        $user = User::query()->find($this->userId);
        // Worker bị gỡ khỏi shop / tắt trong lúc chờ: bỏ qua lô này
        $worker = $user ? Worker::query()->assignedTo($user->id, Worker::TYPE_PUBLISH)->find($this->workerId) : null;
        if (! $user || ! $worker) {
            Log::warning('Lập lịch đăng lô: bỏ qua vì tài khoản / worker không còn hợp lệ', ['user_id' => $this->userId, 'batch_id' => $this->batchId, 'worker_id' => $this->workerId]);

            return;
        }

        $post = $posts->createScheduledFromBatch(
            $user, $this->batchId, $worker, $this->platforms, Carbon::parse($this->scheduledAt), $this->aiContent, $this->idempotencyKey,
        );
        if (! $post) {
            Log::info('Lập lịch đăng lô: lô không có ảnh dùng được', ['user_id' => $this->userId, 'batch_id' => $this->batchId]);
        }
    }
}
