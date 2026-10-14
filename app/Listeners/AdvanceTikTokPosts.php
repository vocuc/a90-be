<?php

namespace App\Listeners;

use App\Events\GenerationOutputFinished;
use App\Services\TikTok\TikTokPostService;

/** Bài đăng đang tạo ảnh: mọi ảnh đã xong thì sang bước nội dung (worker viết hoặc chờ duyệt ngay). */
class AdvanceTikTokPosts
{
    public function __construct(private TikTokPostService $posts) {}

    public function handle(GenerationOutputFinished $event): void
    {
        $this->posts->outputFinished($event->outputId);
    }
}
