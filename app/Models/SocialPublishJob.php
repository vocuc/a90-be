<?php

namespace App\Models;

use App\Enums\OutputStatus;
use App\Enums\PublishDriver;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Job đăng bài lên một nền tảng cho worker: lấy qua GET /api/v1/publish-jobs, trả qua POST /api/v1/publish-jobs/{id}.
 * Mỗi lần bấm đăng tạo 1 job / nền tảng đã chọn. Job là lịch sử từng lần thử (mã lỗi chi tiết cho admin);
 * trạng thái hiện tại của nền tảng nằm ở social_posts.{platform}_status.
 * Dùng chung trạng thái với job ảnh (queued | processing | completed | failed).
 * driver api (TikTok người dùng tự kết nối): không qua worker, queue job PublishTikTokViaApi gọi TikTok;
 * external_id = publish_id, kết quả qua webhook hoặc status/fetch (checked_at: lần hỏi gần nhất).
 * Một bài gửi vào nhiều tài khoản TikTok một lượt: mỗi tài khoản (đích gửi, targetKey) một job. Trạng thái nền tảng tính từ
 * job mới nhất của từng đích, bỏ qua job superseded (job lỗi của lần đăng trước, không đăng lại).
 */
class SocialPublishJob extends Model
{
    protected $table = 'social_publish_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'driver' => PublishDriver::class,
            'status' => OutputStatus::class,
            'lease_expires_at' => 'datetime',
            'checked_at' => 'datetime',
            'finished_at' => 'datetime',
            'superseded' => 'boolean',
        ];
    }

    /** Đích gửi: worker của nền tảng, hoặc một tài khoản TikTok (theo open_id, giữ nguyên khi kết nối lại). */
    public function targetKey(): string
    {
        return $this->driver === PublishDriver::Api
            ? 'api:'.($this->account_open_id ?? $this->publish_account_id)
            : 'worker';
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
