<?php

namespace App\Enums;

enum LedgerType: string
{
    case AdminTopup = 'admin_topup';
    case AdminAdjust = 'admin_adjust';
    case GenerationCharge = 'generation_charge';
    case GenerationRefund = 'generation_refund';
    case TransactionRefund = 'transaction_refund';
}
