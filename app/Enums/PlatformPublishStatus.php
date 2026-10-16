<?php

namespace App\Enums;

/** Trạng thái bài trên một nền tảng (social_posts.{platform}_status). null: bài không đăng lên nền tảng đó. */
enum PlatformPublishStatus: string
{
    // Đã bấm đăng, chờ worker của nền tảng nhận job
    case Queued = 'queued';
    // Worker đang đăng
    case Publishing = 'publishing';
    // Worker đã đăng xong (TikTok: đã lưu nháp)
    case Published = 'published';
    // Worker báo lỗi / quá hạn: người dùng bấm đăng lại nền tảng này
    case Failed = 'failed';

    public function isRunning(): bool
    {
        return $this === self::Queued || $this === self::Publishing;
    }
}
