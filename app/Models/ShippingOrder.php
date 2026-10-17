<?php

namespace App\Models;

use App\Enums\ShippingOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Đơn vận chuyển GHN tạo từ ảnh chat giữa người bán và người mua.
 * address_type: old (tỉnh -> quận -> phường) | new (tỉnh -> phường, không có quận).
 */
class ShippingOrder extends Model
{
    public const ADDRESS_OLD = 'old';

    public const ADDRESS_NEW = 'new';

    public const ADDRESS_TYPES = [self::ADDRESS_OLD, self::ADDRESS_NEW];

    public const DEFAULT_ITEM_NAME = 'Hàng hóa dịch vụ';

    /** Gram. */
    public const DEFAULT_WEIGHT = 2000;

    /** Dưới 20kg: GHN dùng service_type_id = 2, chỉ cần khối lượng tổng. */
    public const MAX_WEIGHT = 19999;

    /** Giới hạn COD của GHN (VND). */
    public const MAX_COD = 50_000_000;

    public const MAX_IMAGES = 10;

    public const REQUIRED_NOTES = ['CHOXEMHANGKHONGTHU', 'CHOTHUHANG', 'KHONGCHOXEMHANG'];

    public const DEFAULT_REQUIRED_NOTE = 'CHOXEMHANGKHONGTHU';

    /** 1 = shop trả ship, 2 = khách trả ship. */
    public const PAYMENT_TYPES = [1, 2];

    /** Các trường AI có thể đánh dấu "không chắc" (FE tô vàng). */
    public const AI_FIELDS = ['to_name', 'to_phone', 'to_address', 'to_province_id', 'to_district_id', 'to_ward_code', 'cod_amount', 'note'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ShippingOrderStatus::class,
            'low_confidence_fields' => 'array',
            'expected_delivery_time' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function isNewAddress(): bool
    {
        return $this->address_type === self::ADDRESS_NEW;
    }

    /** Mã đơn a51 gửi GHN (client_order_code): gửi lại cùng mã thì GHN trả đơn cũ, không tạo trùng. */
    public function clientOrderCode(): string
    {
        return 'A51-'.$this->id;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ShippingOrderImage::class)->orderBy('position')->orderBy('id');
    }
}
