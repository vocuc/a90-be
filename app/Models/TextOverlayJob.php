<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Việc chèn chữ lên ảnh của 1 bài (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.9, 5.11). Worker đăng bài của
 * kênh nhận qua GET /api/v1/post-jobs (type overlay, ưu tiên trước bài đăng). Sửa chữ -> job cũ superseded, tạo job mới.
 */
class TextOverlayJob extends Model
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const SUPERSEDED = 'superseded';

    /** Worker nhận rồi im lặng quá số phút này -> giao lại. */
    public const LEASE_MINUTES = 10;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
