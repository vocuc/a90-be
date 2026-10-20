<?php

namespace App\Enums;

/**
 * Cách đăng bài của một tài khoản (publish_accounts.driver), chép sang job lúc tạo (social_publish_jobs.driver):
 *   worker  admin gắn: worker FlowKit đăng bằng tài khoản đã đăng nhập sẵn (TikTok: lưu nháp)
 *   api     người dùng tự kết nối TikTok (OAuth): backend gọi Content Posting API, ảnh vào hộp thư TikTok
 */
enum PublishDriver: string
{
    case Worker = 'worker';
    case Api = 'api';
}
