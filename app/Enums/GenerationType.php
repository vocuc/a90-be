<?php

namespace App\Enums;

enum GenerationType: string
{
    case Paid = 'paid';
    case Owner = 'owner';
    case Test = 'test';
}
