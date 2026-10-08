<?php

namespace App\Enums;

enum CreativePauseReason: string
{
    case Manual = 'manual';
    case NoValidApiKey = 'no_valid_api_key';
}
