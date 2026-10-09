<?php

namespace App\Console\Commands;

use App\Enums\OutputStatus;
use App\Models\GenerationOutput;
use App\Services\GenerationFinalizer;
use App\Services\WorkerJobService;
use Illuminate\Console\Command;

/**
 * Lưới an toàn: worker giữ job quá hạn thì giao lại (hoặc thất bại khi hết lượt);
 * ảnh nằm trong hàng đợi quá lâu (tính từ lần vào hàng đợi gần nhất) thì đánh dấu thất bại và hoàn Credit.
 */
class SweepStuckGenerations extends Command
{
    protected $signature = 'generations:sweep-stuck';

    protected $description = 'Giao lại job worker giữ quá hạn, hoàn Credit cho các ảnh bị kẹt quá lâu';

    public function handle(WorkerJobService $jobs, GenerationFinalizer $finalizer): int
    {
        $leases = $jobs->releaseExpiredLeases();

        $queuedBefore = now()->subMinutes(config('ai90.generation.stuck_queued_minutes'));
        $ids = GenerationOutput::query()
            ->where('status', OutputStatus::Queued)
            ->where('updated_at', '<', $queuedBefore) // giao lại thì updated_at được làm mới
            ->pluck('id');

        foreach ($ids as $id) {
            $finalizer->fail($id, 'TIMEOUT', 'Quá thời gian chờ worker xử lý.');
        }

        $this->info("Giao lại {$leases['requeued']} ảnh, thất bại {$leases['failed']} ảnh hết lượt, {$ids->count()} ảnh chờ quá lâu.");

        return self::SUCCESS;
    }
}
