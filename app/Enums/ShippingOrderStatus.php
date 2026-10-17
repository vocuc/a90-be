<?php

namespace App\Enums;

/** Trạng thái đơn vận chuyển tạo từ ảnh. */
enum ShippingOrderStatus: string
{
    // Vừa tải ảnh lên, AI đang đọc (job chạy ngầm): chưa sửa / gửi được, chỉ xoá được
    case Reading = 'reading';
    // Đơn nháp: AI đã đọc ảnh, chờ người dùng xem lại và gửi GHN
    case Draft = 'draft';
    // Đã tạo đơn bên GHN (có ghn_order_code)
    case Submitted = 'submitted';
    // GHN từ chối: sửa rồi gửi lại được
    case Failed = 'failed';

    /** Còn sửa / gửi được. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Failed;
    }
}
