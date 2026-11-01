<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Video template của shop (note/quan-ly-video-template.txt ở repo frontend): tên + link video TikTok mẫu + số ảnh cần.
 * image_count null: template tạo trước khi có số ảnh, chưa dùng được cho bài video (note/lap-lich-worker.txt).
 * source_key khác null: template đồng bộ từ máy làm template (D:\video-tmp, PUT /api/v1/video-templates/sync), tên trùng
 * tên template trên máy worker; shop chỉ xem, không sửa / xoá. hidden_at: đã bị xoá khỏi máy làm template -> ẩn.
 * is_enabled: công tắc của shop (cả template đồng bộ); tắt thì không dùng cho bài video mới.
 */
class VideoTemplate extends Model
{
    /** Số ảnh tối đa một template cần (vừa giới hạn ảnh mỗi bài đăng). */
    public const MAX_IMAGE_COUNT = 35;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime', 'is_enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSynced(): bool
    {
        return $this->source_key !== null;
    }

    /** Template đang hiện (chưa bị ẩn do xoá khỏi máy làm template). */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    /** Dùng được cho bài video: đang hiện + đang bật + có số ảnh. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->visible()->where('is_enabled', true)->whereNotNull('image_count');
    }
}
