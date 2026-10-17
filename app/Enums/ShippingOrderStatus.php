<?php

namespace App\Enums;

/** Trạng thái đơn vận chuyển tạo từ ảnh. */
enum ShippingOrderStatus: string
{
    // Đơn nháp: AI vừa đọc ảnh, chờ người dùng xem lại và gửi GHN
    case Draft = 'draft';
    // Đã tạo đơn bên GHN (có ghn_order_code)
    case Submitted = 'submitted';
    // GHN từ chối: sửa rồi gửi lại được
    case Failed = 'failed';

    /** Còn sửa / xoá / gửi được. */
    public function isEditable(): bool
    {
        return $this !== self::Submitted;
    }
}
