<?php

namespace App\Models;

/**
 * Việc "Gắn tag bằng AI" (worker_jobs type tag, a90-fe note/xay-kenh-tiktok/worker-gan-tag.txt): chủ shop chọn sản phẩm ở
 * trang Sản phẩm -> việc queued; worker gắn tag nhận việc, gọi skill Claude /gan-tag-san-pham, trả tag về A51 (trường chủ
 * shop đã sửa giữ nguyên). payload = {product_ids}; result = {updated, skipped, errors}.
 */
class TagJob extends WorkerJob
{
    protected const JOB_TYPES = [self::TYPE_TAG];

    /** Worker nhận rồi im lặng quá số phút này -> giao lại. */
    public const LEASE_MINUTES = 30;

    /** Số sản phẩm tối đa mỗi lượt. */
    public const MAX_PRODUCTS = 100;
}
