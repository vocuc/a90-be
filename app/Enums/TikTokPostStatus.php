<?php

namespace App\Enums;

enum TikTokPostStatus: string
{
    case Generating = 'generating';
    case PendingReview = 'pending_review';
    // Đã bấm đăng, chờ worker nhận job (không hết hạn)
    case Queued = 'queued';
    // Worker đang lưu nháp lên TikTok
    case Publishing = 'publishing';
    // Worker đã lưu nháp TikTok; nhân viên A51 tự đăng nháp, A51 không theo dõi tiếp
    case Published = 'published';

    /** Đã bấm đăng: không sửa, không xoá. */
    public function isLocked(): bool
    {
        return $this === self::Queued || $this === self::Publishing;
    }
}
