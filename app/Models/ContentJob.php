<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Việc DỰNG NỘI DUNG của 1 bài ảnh (a90-fe note/xay-kenh-tiktok/worker-content.txt, người dùng chốt 2026-11-21):
 * A51 chỉ đưa nguyên liệu (ảnh theo thứ tự slide, chữ, template chữ, hook, dạng bài), CONTENT-WORKER của shop dựng ảnh
 * hoàn chỉnh (in chữ, ghép bìa...) rồi gửi lại. Nhận qua GET /api/v1/content-jobs. Sửa chữ / làm lại -> job cũ superseded.
 * (Bảng cũ text_overlay_jobs: trước đây worker đăng bài chèn chữ.)
 */
class ContentJob extends Model
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
