<?php

namespace App\Models;

use App\Enums\OutputStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Job đăng bài TikTok cho worker: lấy qua GET /api/v1/publish-jobs, trả qua POST /api/v1/publish-jobs/{id}.
 * Mỗi lần bấm đăng 1 job. Dùng chung trạng thái với job ảnh (queued | processing | completed | failed).
 */
class TikTokPublishJob extends Model
{
    protected $table = 'tiktok_publish_jobs';

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

    public function tiktokAccount(): BelongsTo
    {
        return $this->belongsTo(TikTokAccount::class, 'tiktok_account_id');
    }
}
