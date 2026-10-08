<?php

namespace App\Enums;

enum RevenueStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Withdrawn = 'withdrawn';
    case Reversed = 'reversed';
}
