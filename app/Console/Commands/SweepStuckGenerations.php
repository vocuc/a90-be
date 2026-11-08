<?php

namespace App\Console\Commands;

use App\Services\WorkerJobService;
use Illuminate\Console\Command;

/**
 * Lưới an toàn: worker giữ job quá hạn thì giao lại (hoặc thất bại khi hết lượt).
 * Ảnh còn trong hàng đợi (chưa worker nào nhận) thì chờ mãi, không tự thất bại; người dùng tự bấm
 * "Hủy ảnh đang chờ" ở trang lô để hoàn tiền (POST /image-batches/{id}/cancel).
 */
class SweepStuckGenerations extends Command
{
    protected $signature = 'generations:sweep-stuck';

    protected $description = 'Giao lại job worker giữ quá hạn, thất bại và hoàn Credit khi hết lượt';

    public function handle(WorkerJobService $jobs): int
    {
        $leases = $jobs->releaseExpiredLeases();

        $this->info("Giao lại {$leases['requeued']} ảnh, thất bại {$leases['failed']} ảnh hết lượt.");

        return self::SUCCESS;
    }
}
