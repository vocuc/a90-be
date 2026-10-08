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
        'api_key.cooldown_minutes' => 10,
    ],

    'generation' => [
        // Output kẹt quá lâu sẽ bị đánh dấu thất bại và hoàn Credit.
        'stuck_processing_minutes' => 15,
        'stuck_queued_minutes' => 30,
        // Số request tạo ảnh tối đa mỗi phút cho mỗi user.
        'rate_limit_per_minute' => 10,
    ],

    'auth' => [
        'token_ttl_days' => 30,
    ],

    'providers' => [
        'gemini' => [
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'timeout' => (int) env('GEMINI_TIMEOUT_SECONDS', 120),
        ],
    ],

];
