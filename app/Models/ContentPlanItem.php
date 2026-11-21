<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bài kế hoạch: 1 slot của kỳ (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.6). */
class ContentPlanItem extends Model
{
    public const DRAFT = 'draft';

    public const CANCELLED = 'cancelled';

    /** Đã sinh bài đăng (social_post_id). */
    public const POSTED = 'posted';

    public const FORMAT_PHOTO = 'photo';

    public const FORMAT_VIDEO = 'video';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'images' => 'array',
            'outfit' => 'array',
            // Bài lấy ảnh bằng lô tự động mới (unboxing): { product_id }
            'product_batch' => 'array',
            'hashtags' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class, 'plan_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function textTemplate(): BelongsTo
    {
        return $this->belongsTo(ChannelTextTemplate::class, 'text_template_id');
    }

    public function videoTemplate(): BelongsTo
    {
        return $this->belongsTo(VideoTemplate::class);
    }
}
