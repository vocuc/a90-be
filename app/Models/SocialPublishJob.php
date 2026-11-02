<?php

namespace App\Models;

use App\Enums\OutputStatus;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần worker nhận bài (note/gop-luong-dang-bai.txt ở repo frontend): GET /api/v1/post-jobs, trả qua
 * POST /api/v1/post-jobs/{id}. 1 job / bài, chứa mọi nền tảng bài cần đăng (platforms); worker báo kết quả 1 lần cho
 * cả job. Job là lịch sử từng lần thử (mã lỗi chi tiết cho admin); trạng thái hiện tại của nền tảng nằm ở
 * social_posts.{platform}_status. Không có hạn: worker im lặng thì chủ shop bấm "Tạo lại" (job cũ superseded).
 * Dùng chung trạng thái với job ảnh (queued | processing | completed | failed); job tạo ra là processing luôn.
 */
class SocialPublishJob extends Model
{
    protected $table = 'social_publish_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'status' => OutputStatus::class,
            'finished_at' => 'datetime',
            'superseded' => 'boolean',
        ];
    }

    /** @return SocialPlatform[] */
    public function platformEnums(): array
    {
        return array_map(fn (string $value) => SocialPlatform::from($value), $this->platforms ?? []);
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
