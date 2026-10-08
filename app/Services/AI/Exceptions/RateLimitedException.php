<?php

namespace App\Services\AI\Exceptions;

/** Key hết quota hoặc bị giới hạn tần suất (429): tạm bỏ qua key và thử key khác. */
class RateLimitedException extends ProviderException
{
    public function errorCode(): string
    {
        return 'API_KEY_RATE_LIMITED';
    }
}
