<?php

namespace App\Models;

/**
 * Việc "xây dựng chủ đề tự động" của 1 kênh (worker_jobs type topic, a90-fe note/xay-kenh-tiktok/worker-lap-chu-de.txt):
 * chủ shop bấm nút ở tab Chủ đề -> việc queued; worker lập chủ đề nhận việc, gọi skill Claude /lap-chu-de, trả chủ đề
 * về A51 (lưu luôn, bật sẵn). payload = {count, note}; result = {created, skipped}.
 */
class TopicJob extends WorkerJob
{
    protected const JOB_TYPES = [self::TYPE_TOPIC];

    /** Worker nhận rồi im lặng quá số phút này (Claude lập chủ đề lâu) -> giao lại. */
    public const LEASE_MINUTES = 30;

    public const COUNT_MIN = 1;

    public const COUNT_MAX = 30;

    public const COUNT_DEFAULT = 10;

    public const NOTE_MAX = 500;
}
