<?php

namespace App\Enums;

enum GenerationType: string
{
    case Paid = 'paid';
    case Owner = 'owner';
    case Test = 'test';
    // Ảnh của bài kế hoạch nội dung: không creative, không trừ Credit (PlannerService)
    case Plan = 'plan';
}
