<?php

namespace App\Listeners;

use App\Events\GenerationOutputFinished;
use App\Services\TikTok\TikTokPostService;

/** Bài đăng đang tạo nội dung: mọi ảnh đã xong thì giao job viết nội dung cho worker. */
class AdvanceTikTokPosts
{
    public function __construct(private TikTokPostService $posts) {}

    public function handle(GenerationOutputFinished $event): void
    {
        $this->posts->outputFinished($event->outputId);
    }
}
