<?php

namespace App\Enums;

/**
 * Trạng thái chung của bài. Sau khi bấm đăng, tính từ trạng thái từng nền tảng
 * (tiktok_status / facebook_status / instagram_status) trong SocialPost::syncStatus().
 */
enum SocialPostStatus: string
{
    case Generating = 'generating';
    // Chờ gửi: chưa bấm đăng, hoặc đã đăng xong nhưng có nền tảng lỗi (người dùng đăng lại các nền tảng lỗi)
    case PendingReview = 'pending_review';
    // Đã bấm đăng, chưa worker nào nhận (job chờ không hết hạn)
    case Queued = 'queued';
    // Có worker đang đăng ít nhất 1 nền tảng
    case Publishing = 'publishing';
    // Mọi nền tảng đã chọn đều đăng xong
    case Published = 'published';

    /** Đã bấm đăng: không sửa, không xoá. */
    public function isLocked(): bool
    {
        return $this === self::Queued || $this === self::Publishing;
    }
}
