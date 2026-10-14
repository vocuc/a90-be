<?php

namespace App\Console\Commands;

use App\Enums\TikTokPostStatus;
use App\Models\TikTokPost;
use App\Services\TikTok\TikTokContentJobService;
use App\Services\TikTok\TikTokPostService;
use Illuminate\Console\Command;

/**
 * Lưới an toàn cho bài TikTok đang tạo nội dung: ảnh đã xong mà bài chưa sang bước nội dung thì chuyển tiếp,
 * giao lại job worker giữ quá hạn, dùng nội dung mặc định cho job chờ quá lâu / hết lượt.
 */
class SweepTikTokPosts extends Command
{
    protected $signature = 'tiktok:sweep';

    protected $description = 'Xử lý bài TikTok kẹt ở bước tạo nội dung';

    public function handle(TikTokPostService $posts, TikTokContentJobService $jobs): int
    {
        TikTokPost::query()
            ->where('status', TikTokPostStatus::Generating)
            ->whereDoesntHave('contentJob')
            ->pluck('id')
            ->each(fn (int $id) => $posts->advanceIfReady($id));

        $leases = $jobs->releaseExpiredLeases();
        $stuck = $jobs->failStuckQueued();

        $this->info("Giao lại {$leases['requeued']} job nội dung, {$leases['failed']} job hết lượt, {$stuck} job chờ quá lâu.");

        return self::SUCCESS;
    }
}
