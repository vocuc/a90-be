<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Video template của shop (note/quan-ly-video-template.txt ở repo frontend): tên + link video TikTok mẫu + số ảnh cần.
 * image_count null: template tạo trước khi có số ảnh, chưa dùng được cho bài video (note/lap-lich-worker.txt).
 */
class VideoTemplate extends Model
{
    /** Số ảnh tối đa một template cần (vừa giới hạn ảnh mỗi bài đăng). */
    public const MAX_IMAGE_COUNT = 35;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
