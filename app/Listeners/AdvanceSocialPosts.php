<?php

namespace App\Listeners;

use App\Events\GenerationOutputFinished;
use App\Services\Social\SocialPostService;

/** Bài đăng đang tạo ảnh: mọi ảnh đã xong thì sang bước nội dung (worker viết hoặc chờ duyệt ngay). */
class AdvanceSocialPosts
{
    public function __construct(private SocialPostService $posts) {}

    public function handle(GenerationOutputFinished $event): void
    {
        $this->posts->outputFinished($event->outputId);
    }
}
