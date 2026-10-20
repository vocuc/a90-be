<?php

namespace App\Console\Commands;

use App\Enums\SocialPostStatus;
use App\Models\SocialPost;
use App\Services\Social\SocialContentJobService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishJobService;
use App\Services\TikTok\TikTokApiPublisher;
use Illuminate\Console\Command;

/**
 * Lưới an toàn cho bài đăng đang tạo nội dung: ảnh đã xong mà bài chưa sang bước nội dung thì chuyển tiếp,
 * giao lại job worker giữ quá hạn, dùng nội dung mặc định cho job chờ quá lâu / hết lượt.
 * Job đăng bài worker giữ quá hạn: nền tảng đó thất bại. Job gửi TikTok qua API chưa có kết quả: hỏi TikTok (status/fetch),
 * quá hạn thì thất bại.
 */
class SweepSocialPosts extends Command
{
    protected $signature = 'social:sweep';

    protected $description = 'Xử lý bài đăng kẹt ở bước tạo nội dung / đăng bài';

    public function handle(SocialPostService $posts, SocialContentJobService $jobs, SocialPublishJobService $publishJobs, TikTokApiPublisher $tiktok): int
    {
        SocialPost::query()
            ->where('status', SocialPostStatus::Generating)
            ->whereDoesntHave('contentJob')
            ->pluck('id')
            ->each(fn (int $id) => $posts->advanceIfReady($id));

        $leases = $jobs->releaseExpiredLeases();
        $stuck = $jobs->failStuckQueued();

        // Hỏi trước khi cho quá hạn: bài đã vào hộp thư mà webhook chưa về thì không bị tính thất bại
        $tiktokChecked = $tiktok->checkPending();
        $publishFailed = $publishJobs->releaseExpiredLeases();

        $this->info("Giao lại {$leases['requeued']} job nội dung, {$leases['failed']} job hết lượt, {$stuck} job chờ quá lâu, {$publishFailed} job đăng bài quá hạn, hỏi TikTok {$tiktokChecked} job.");

        return self::SUCCESS;
    }
}
