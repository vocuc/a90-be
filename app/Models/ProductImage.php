<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ảnh sản phẩm. File giữ lại khi xoá vì lượt tạo ảnh cũ dùng thẳng đường dẫn này. */
class ProductImage extends Model
{
    protected $guarded = ['id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
