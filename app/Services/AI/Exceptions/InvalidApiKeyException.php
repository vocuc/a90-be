<?php

namespace App\Services\AI\Exceptions;

/** Key sai hoặc bị thu hồi: đánh dấu invalid và thử key khác. */
class InvalidApiKeyException extends ProviderException
{
    public function errorCode(): string
    {
        return 'API_KEY_INVALID';
    }
}
