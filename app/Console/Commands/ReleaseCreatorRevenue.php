<?php

namespace App\Console\Commands;

use App\Services\RevenueService;
use Illuminate\Console\Command;

class ReleaseCreatorRevenue extends Command
{
    protected $signature = 'revenue:release';

    protected $description = 'Chuyển doanh thu Creator đã hết thời gian giữ từ pending sang available';

    public function handle(RevenueService $revenues): int
    {
        $count = $revenues->releaseDue();
        $this->info("Đã giải phóng {$count} khoản doanh thu.");

        return self::SUCCESS;
    }
}
