<?php

namespace App\Console\Commands;

use App\Enums\SocialPostStatus;
use App\Models\SocialPost;
use App\Services\Social\SocialContentJobService;
use App\Services\Social\SocialPostService;
use Illuminate\Console\Command;

/**
 * Lưới an toàn cho bài đăng đang tạo nội dung: ảnh đã xong mà bài chưa sang bước nội dung thì chuyển tiếp,
 * job AI viết nội dung chờ / chạy quá lâu thì dùng nội dung mặc định và hoàn Credit.
 * Job đăng bài không có hạn (note/gop-luong-dang-bai.txt ở repo frontend): worker im lặng thì chủ shop bấm "Tạo lại".
 */
class SweepSocialPosts extends Command
{
    protected $signature = 'social:sweep';

    protected $description = 'Xử lý bài đăng kẹt ở bước tạo nội dung';

    public function handle(SocialPostService $posts, SocialContentJobService $jobs): int
    {
        SocialPost::query()
            ->where('status', SocialPostStatus::Generating)
            ->whereDoesntHave('contentJob')
            ->pluck('id')
            ->each(fn (int $id) => $posts->advanceIfReady($id));

        $stuck = $jobs->failStuck();

        $this->info("{$stuck} job AI viết nội dung quá lâu.");

        return self::SUCCESS;
    }
}
