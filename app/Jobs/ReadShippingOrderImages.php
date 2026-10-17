<?php

namespace App\Jobs;

use App\Enums\ShippingOrderStatus;
use App\Models\ShippingOrder;
use App\Services\Shipping\ShippingOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** AI đọc ảnh chat của đơn vừa tải lên (chạy ngầm, request parse trả về ngay với status reading). */
class ReadShippingOrderImages implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // Claude đọc 10 ảnh mất tới ~1 phút, cộng thời gian khớp danh mục GHN
    public int $timeout = 180;

    public function __construct(public int $orderId) {}

    public function handle(ShippingOrderService $orders): void
    {
        $orders->readImages($this->orderId);
    }

    /** Hết lượt thử: chuyển nháp trống để người dùng tự nhập, không kẹt ở "đang đọc ảnh". */
    public function failed(?Throwable $e): void
    {
        ShippingOrder::query()
            ->whereKey($this->orderId)
            ->where('status', ShippingOrderStatus::Reading)
            ->update(['status' => ShippingOrderStatus::Draft]);
    }
}
