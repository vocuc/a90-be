<?php

namespace App\Models;

use App\Enums\OutputStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Job viết tiêu đề / nội dung / hashtag cho worker: lấy qua GET /api/v1/content-jobs, trả qua POST /api/v1/content-jobs/{id}.
 * Dùng chung trạng thái với job ảnh (queued | processing | completed | failed).
 */
class TikTokContentJob extends Model
{
    protected $table = 'tiktok_content_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OutputStatus::class,
            'lease_expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(TikTokPost::class, 'tiktok_post_id');
    }
}
