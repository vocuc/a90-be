<?php

namespace App\Jobs;

use App\Services\TikTok\TikTokApiPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Gửi một job đăng bài TikTok driver api lên TikTok (TikTokApiPublisher). TikTok tạm lỗi thì thử lại,
 * hết lượt thì job thất bại TIKTOK_UNAVAILABLE.
 */
class PublishTikTokViaApi implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var int[] giây chờ trước lần thử 2, 3, 4 */
    public array $backoff = [30, 120, 300];

    public int $timeout = 300;

    public function __construct(public int $publishJobId) {}

    public function handle(TikTokApiPublisher $publisher): void
    {
        $publisher->run($this->publishJobId);
    }

    public function failed(?Throwable $e): void
    {
        app(TikTokApiPublisher::class)->giveUp($this->publishJobId, $e ?? new \RuntimeException('Job gửi TikTok thất bại.'));
    }
}
