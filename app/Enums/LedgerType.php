<?php

namespace App\Enums;

enum LedgerType: string
{
    case AdminTopup = 'admin_topup';
    case AdminAdjust = 'admin_adjust';
    case GenerationCharge = 'generation_charge';
    case GenerationRefund = 'generation_refund';
    case TransactionRefund = 'transaction_refund';
    // AI viết nội dung bài đăng (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend)
    case SocialContentCharge = 'social_content_charge';
    case SocialContentRefund = 'social_content_refund';
}
