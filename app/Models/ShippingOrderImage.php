<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ảnh chat người dùng tải lên cho một đơn vận chuyển, giữ lại để đối chiếu khi sửa đơn. */
class ShippingOrderImage extends Model
{
    protected $guarded = ['id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShippingOrder::class, 'shipping_order_id');
    }
}
