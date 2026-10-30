<?php

namespace App\Jobs;

use App\Services\Social\SocialContentJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** AI viết nội dung cho bài vừa tạo (chạy ngầm, bài ở generating trong lúc chờ). */
class WriteSocialPostContent implements ShouldQueue
{
    use Queueable;

    // Không thử lại: lỗi API đã được SDK thử lại, lỗi khác thì dùng nội dung mặc định và hoàn Credit
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $jobId) {}

    public function handle(SocialContentJobService $jobs): void
    {
        $jobs->run($this->jobId);
    }

    public function failed(?Throwable $e): void
    {
        app(SocialContentJobService::class)->failJob($this->jobId, 'Lỗi khi AI viết nội dung: '.($e?->getMessage() ?? 'không rõ'));
    }
}
