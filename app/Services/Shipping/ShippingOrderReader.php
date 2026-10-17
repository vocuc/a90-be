<?php

namespace App\Services\Shipping;

/** Đọc ảnh chat giữa người bán và người mua -> thông tin người nhận của 1 đơn. */
interface ShippingOrderReader
{
    /**
     * @param  array<int, array{contents: string, mime: string}>  $images  theo thứ tự tải lên
     * @return array{
     *     to_name: ?string, to_phone: ?string, street: ?string, ward: ?string, district: ?string, province: ?string,
     *     raw_address: ?string, cod_amount: ?int, note: ?string, low_confidence_fields: string[]
     * }|null null khi không đọc được (lỗi API, từ chối, kết quả hỏng): đơn nháp để trống cho người dùng tự nhập
     */
    public function read(array $images): ?array;
}
