<?php

namespace App\Enums;

enum CreativeStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Archived = 'archived';
}
