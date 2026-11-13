<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ảnh của bài đăng, một trong hai loại:
 * - liên kết tới một output (ảnh tạo trên A51): ảnh, url, trạng thái luôn đọc từ output;
 * - ảnh upload thẳng (bài lookbook cũ): generation_id / output_id = null, file ở image_path /
 *   thumb_path.
 * Chỗ đọc ảnh bài đăng dùng isUsable() / imagePath() / thumbPath() để không phải phân biệt hai loại.
 */
class SocialPostImage extends Model
{
    protected $table = 'social_post_images';

    protected $guarded = ['id'];

    public function isUploaded(): bool
    {
        return $this->output_id === null && $this->image_path !== null;
    }

    /** Dùng được để đăng / gửi AI: ảnh upload luôn dùng được; ảnh output phải xong và không bị tắt (cần load output). */
    public function isUsable(): bool
    {
        return $this->isUploaded() || (bool) $this->output?->isUsable();
    }

    public function imagePath(): ?string
    {
        return $this->isUploaded() ? $this->image_path : $this->output?->image_path;
    }

    public function thumbPath(): ?string
    {
        return $this->isUploaded() ? ($this->thumb_path ?: $this->image_path) : $this->output?->thumbPathOrOriginal();
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(GenerationOutput::class, 'output_id');
    }
}
