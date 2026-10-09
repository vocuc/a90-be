<?php

return [

    // Disk lưu ảnh: "s3" trên production, "media" (local) khi phát triển.
    'media_disk' => env('MEDIA_DISK', 's3'),

    // Thời hạn của signed URL trả về cho frontend.
    'signed_url_ttl_minutes' => (int) env('SIGNED_URL_TTL_MINUTES', 15),

    // Giá trị mặc định cho bảng settings (admin sửa được trong DB).
    'defaults' => [
        'platform_fee_percent' => 10,
        'revenue_hold_days' => 7,
        'generation.default_max_outputs' => 5,
        'generation.max_outputs_limit' => 10,
        'generation.input_max_size_kb' => 10240,
    ],

    'generation' => [
        // Ảnh chờ quá lâu mà không worker nào nhận sẽ bị đánh dấu thất bại và hoàn Credit.
        'stuck_queued_minutes' => 30,
        // Số request tạo ảnh tối đa mỗi phút cho mỗi user.
        'rate_limit_per_minute' => 10,
    ],

    'auth' => [
        'token_ttl_days' => 30,
    ],

    'worker' => [
        // Worker phải trả kết quả trong thời gian này, quá hạn thì job được giao lại cho worker khác.
        'lease_minutes' => 15,
        // Số lần giao job tối đa; quá số lần này thì ảnh thất bại và hoàn Credit.
        'max_attempts' => 3,
        // Dung lượng ảnh kết quả tối đa worker được gửi lên (KB).
        'output_max_size_kb' => 20480,
    ],

];
