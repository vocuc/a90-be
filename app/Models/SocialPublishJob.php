<?php

namespace App\Models;

use App\Enums\OutputStatus;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Job đăng bài lên một nền tảng cho worker: lấy qua GET /api/v1/publish-jobs, trả qua POST /api/v1/publish-jobs/{id}.
 * Mỗi lần bấm đăng tạo 1 job / nền tảng đã chọn. Job là lịch sử từng lần thử (mã lỗi chi tiết cho admin);
 * trạng thái hiện tại của nền tảng nằm ở social_posts.{platform}_status.
 * Dùng chung trạng thái với job ảnh (queued | processing | completed | failed).
 */
class SocialPublishJob extends Model
{
    protected $table = 'social_publish_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'status' => OutputStatus::class,
            'lease_expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function publishAccount(): BelongsTo
    {
        return $this->belongsTo(PublishAccount::class, 'publish_account_id');
    }
}
