<?php

namespace App\Models;

/**
 * Việc DỰNG NỘI DUNG của 1 bài ảnh (worker_jobs type content; a90-fe note/xay-kenh-tiktok/worker-content.txt):
 * A51 chỉ đưa nguyên liệu (ảnh theo thứ tự slide, chữ, template chữ, hook, dạng bài), CONTENT-WORKER của shop dựng ảnh
 * hoàn chỉnh (in chữ, ghép bìa...) rồi gửi lại qua /api/v1/worker-jobs. Sửa chữ / làm lại -> việc cũ superseded.
 */
class ContentJob extends WorkerJob
{
    protected const JOB_TYPES = [self::TYPE_CONTENT];

    /** Worker nhận rồi im lặng quá số phút này -> giao lại. */
    public const LEASE_MINUTES = 10;
}
