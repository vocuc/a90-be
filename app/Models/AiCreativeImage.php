<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreativeImage extends Model
{
    protected $guarded = ['id'];

    public function creative(): BelongsTo
    {
        return $this->belongsTo(AiCreative::class, 'creative_id');
    }

    /** Ảnh upload trước khi có thumbnail thì dùng lại ảnh chính. */
    public function thumbPathOrOriginal(): string
    {
        return $this->thumb_path ?? $this->path;
    }
}
