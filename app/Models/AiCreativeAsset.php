<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nguyên liệu ẩn của Creative (ảnh cho trường hidden_image). */
class AiCreativeAsset extends Model
{
    protected $guarded = ['id'];

    public function creative(): BelongsTo
    {
        return $this->belongsTo(AiCreative::class, 'creative_id');
    }
}
