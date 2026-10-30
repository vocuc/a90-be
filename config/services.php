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

    // Tạo đơn GHN: token + ShopId do từng shop tự nhập. Staging: https://dev-online-gateway.ghn.vn
    'ghn' => [
        'base_url' => rtrim(env('GHN_BASE_URL', 'https://online-gateway.ghn.vn'), '/').'/shiip/public-api',
        'timeout' => 15,
        // Danh mục tỉnh / quận / phường ít đổi
        'catalog_cache_hours' => 24,
    ],

    // AI đọc ảnh chat để tạo đơn vận chuyển, AI viết nội dung bài đăng: gọi thẳng Claude API, không qua worker
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'shipping_model' => env('ANTHROPIC_SHIPPING_MODEL', 'claude-haiku-5-5'),
        // AI viết tiêu đề / nội dung / hashtag bài đăng
        'social_model' => env('ANTHROPIC_SOCIAL_MODEL', 'claude-haiku-5-5'),
        'timeout' => 60,
    ],

    // Đăng TikTok qua API (người dùng tự kết nối): Login Kit + Content Posting API chế độ Upload (gửi vào hộp thư TikTok).
    // Đặc tả: note/dang-tiktok-qua-api.txt ở repo frontend.
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        // Trang frontend TikTok trả người dùng về (kèm code, state), khớp chính xác URI đăng ký trên Developer Portal.
        // Frontend gửi code + state cho POST /api/v1/social/tiktok/oauth/callback.
        'redirect_uri' => env('TIKTOK_REDIRECT_URI', 'https://a51.vn/mxh/tiktok/author'),
        // user.info.profile: lấy username để hiện @username
        'scopes' => 'user.info.basic,user.info.profile,video.upload',
        'media_disk' => env('TIKTOK_MEDIA_DISK', 's3_tiktok'),
        'media_url' => rtrim((string) env('TIKTOK_MEDIA_URL', ''), '/'),
        // Webhook: chênh lệch thời gian tối đa của chữ ký (giây)
        'webhook_tolerance_seconds' => 300,
        // Job đang gửi: hỏi lại TikTok (status/fetch) mỗi bấy nhiêu phút khi webhook chưa về; quá hạn thì thất bại
        'status_check_minutes' => 5,
        'publish_timeout_minutes' => 60,
        // Số tài khoản TikTok tối đa một tài khoản A51 được kết nối
        'max_accounts' => (int) env('TIKTOK_MAX_ACCOUNTS', 10),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
