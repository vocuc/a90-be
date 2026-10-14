<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Đăng nhập bằng Google: OAuth Client ID (loại Web application), nhiều ID cách nhau bằng dấu phẩy
    'google' => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_ID', ''))))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Đăng bài TikTok: Login Kit (OAuth) + Content Posting API (Upload: gửi vào hộp thư TikTok), xem note/uy-quyen-tiktok.txt
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        // {BACKEND}/api/v1/tiktok/oauth/callback, khớp chính xác URI đăng ký trên Developer Portal
        'redirect_uri' => env('TIKTOK_REDIRECT_URI'),
        // user.info.profile: lấy username để hiển thị @username và tạo link bài đăng
        'scopes' => 'user.info.basic,user.info.profile,video.upload',
        // Trang frontend nhận kết quả ủy quyền: {FRONTEND_URL}{redirect}?tiktok=...
        'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),
        // Ảnh gửi TikTok (PULL_FROM_URL): disk public gắn tên miền đã xác minh với TikTok, vd https://media.a51.vn
        'media_disk' => env('TIKTOK_MEDIA_DISK', 's3_tiktok'),
        'media_url' => rtrim((string) env('TIKTOK_MEDIA_URL', ''), '/'),
        // Webhook: chênh lệch thời gian tối đa của chữ ký (giây)
        'webhook_tolerance_seconds' => 300,
    ],

];
