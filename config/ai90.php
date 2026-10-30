<?php

return [

    // Disk lưu ảnh: "s3" trên production, "media" (local) khi phát triển.
    'media_disk' => env('MEDIA_DISK', 's3'),

    // Thời hạn của signed URL trả về cho frontend.
    'signed_url_ttl_minutes' => (int) env('SIGNED_URL_TTL_MINUTES', 15),

    // Giá trị mặc định cho bảng settings (admin sửa được trong DB).
    'defaults' => [
        'creator_share_percent' => 10,
        'revenue_hold_days' => 7,
        'generation.default_max_outputs' => 5,
        'generation.max_outputs_limit' => 10,
        'generation.input_max_size_kb' => 10240,
        // Số nguyên liệu ẩn (trường hidden_image) tối đa của một Creative.
        'generation.max_hidden_images' => 5,
        // Giá mỗi lần AI viết tiêu đề / nội dung / hashtag bài đăng (tạo bài bật AI hoặc bấm "Viết lại bằng AI").
        'social.ai_content_price' => 2000,
    ],

    'generation' => [
        // Ảnh chờ quá lâu mà không worker nào nhận sẽ bị đánh dấu thất bại và hoàn Credit.
        'stuck_queued_minutes' => 30,
        // Số request tạo ảnh tối đa mỗi phút cho mỗi user.
        'rate_limit_per_minute' => 10,
    ],

    // Tài khoản admin do AdminSeeder tạo.
    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@a90.local'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'social' => [
        // Công tắc tổng của "AI viết nội dung" (Claude API, note/ai-viet-noi-dung-bai-dang.txt ở repo frontend).
        // Bật: người dùng thấy công tắc khi tạo bài và nút "Viết lại bằng AI". Tắt: chỉ tự nhập.
        // TIKTOK_AI_CONTENT: tên cũ, vẫn đọc để không phải sửa .env đang chạy.
        'ai_content' => (bool) env('SOCIAL_AI_CONTENT', env('TIKTOK_AI_CONTENT', false)),
        // AI chọn tối đa số hashtag này, chỉ trong kho hashtag của shop.
        'ai_max_hashtags' => 5,
        // Số ảnh đầu của bài (ảnh bìa trước) gửi cho AI xem.
        'ai_max_images' => 4,
    ],

    'auth' => [
        'token_ttl_days' => 30,
    ],

    'worker' => [
        // Worker phải trả kết quả trong thời gian này, quá hạn thì job được giao lại cho worker khác.
        'lease_minutes' => 15,
        // Số lần giao job tối đa; quá số lần này thì ảnh thất bại và hoàn Credit.
        'max_attempts' => 3,
        // Job đăng bài (GET /api/v1/publish-jobs, 1 job / nền tảng): quá hạn thì nền tảng đó thất bại, không giao lại.
        'publish_lease_minutes' => 15,
        // Dung lượng ảnh kết quả tối đa worker được gửi lên (KB).
        'output_max_size_kb' => 20480,
    ],

];
