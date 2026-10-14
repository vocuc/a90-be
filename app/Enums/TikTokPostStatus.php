<?php

namespace App\Enums;

enum TikTokPostStatus: string
{
    case Generating = 'generating';
    case PendingReview = 'pending_review';
    case Publishing = 'publishing';
    // Đã vào hộp thư TikTok, chờ người dùng mở app TikTok để đăng
    case InboxDelivered = 'inbox_delivered';
    case Published = 'published';
}
