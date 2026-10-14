<?php

namespace App\Enums;

enum TikTokAccountStatus: string
{
    case Active = 'active';
    case Expired = 'expired'; // làm mới token thất bại / refresh token hết hạn
    case Revoked = 'revoked'; // người dùng gỡ quyền trên TikTok hoặc huỷ kết nối trên A51
}
