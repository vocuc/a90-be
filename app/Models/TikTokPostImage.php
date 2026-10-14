<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Liên kết bài đăng <-> một output; ảnh, url, trạng thái luôn đọc từ output. */
class TikTokPostImage extends Model
{
    protected $table = 'tiktok_post_images';

    protected $guarded = ['id'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(TikTokPost::class, 'tiktok_post_id');
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
