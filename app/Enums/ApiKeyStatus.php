<?php

namespace App\Enums;

enum ApiKeyStatus: string
{
    case Active = 'active';
    case Invalid = 'invalid';
    case QuotaExceeded = 'quota_exceeded';
    case Disabled = 'disabled';
}
