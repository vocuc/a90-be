<?php

namespace App\Enums;

/** Trạng thái kết nối TikTok của tài khoản driver api (publish_accounts.connection_status). */
enum TikTokConnectionStatus: string
{
    case Active = 'active';
    // Làm mới token thất bại / TikTok báo token không hợp lệ: người dùng kết nối lại
    case Expired = 'expired';
    // Người dùng huỷ kết nối trên A51 hoặc gỡ quyền A51 trong app TikTok
    case Revoked = 'revoked';
}
