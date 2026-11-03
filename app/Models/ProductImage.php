<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ảnh sản phẩm. File giữ lại khi xoá vì lượt tạo ảnh cũ dùng thẳng đường dẫn này.
 * path là ảnh gốc (dùng để tạo ảnh), thumb_path chỉ để hiển thị.
 */
class ProductImage extends Model
{
    /** Chiều rộng tối đa (px) của thumbnail, chiều cao theo tỷ lệ. */
    public const THUMB_WIDTH = 400;

    protected $guarded = ['id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function thumbPathOrOriginal(): string
    {
        return $this->thumb_path ?? $this->path;
    }
}
