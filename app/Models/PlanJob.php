<?php

namespace App\Models;

/**
 * Việc "Lập kế hoạch mới" của 1 kênh (worker_jobs type plan, a90-fe note/xay-kenh-tiktok/worker-lap-ke-hoach.txt):
 * chủ shop bấm nút ở tab Kế hoạch -> việc queued; worker điều phối nhận việc, chạy skill Claude /lap-ke-hoach <id kênh>
 * (skill tự đọc / gửi kế hoạch qua API channel-plan), xong gửi báo cáo ngắn. payload = {note}; result = {summary, plan_id}.
 */
class PlanJob extends WorkerJob
{
    protected const JOB_TYPES = [self::TYPE_PLAN];

    /** Worker nhận rồi im lặng quá số phút này -> giao lại (điều phối gia hạn định kỳ khi Claude còn chạy). */
    public const LEASE_MINUTES = 30;

    public const NOTE_MAX = 500;

    /** Báo cáo cuối của Claude lưu tối đa số ký tự này. */
    public const SUMMARY_MAX = 4000;
}
