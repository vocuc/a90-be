<?php

namespace App\Models;

use App\Enums\OutputStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần AI viết tiêu đề / nội dung / hashtag khi tạo bài (Claude API qua queue job WriteSocialPostContent).
 * charge_amount: Credit đã trừ, hoàn lại khi AI không viết được. worker_id / lease_expires_at: cột cũ của luồng worker, không dùng.
 * Dùng chung trạng thái với job ảnh (queued | processing | completed | failed).
 */
class SocialContentJob extends Model
{
    protected $table = 'social_content_jobs';

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
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }
}
