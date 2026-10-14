<?php

namespace App\Enums;

enum TikTokPostStatus: string
{
    case Generating = 'generating';
    case PendingReview = 'pending_review';
    case Publishing = 'publishing';
    case Published = 'published';
}
