<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Liên kết lô <-> một output; ảnh, url, trạng thái luôn đọc từ output. */
class ImageBatchImage extends Model
{
    protected $guarded = ['id'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImageBatch::class, 'image_batch_id');
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
