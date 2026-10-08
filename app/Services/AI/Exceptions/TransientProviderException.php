<?php

namespace App\Services\AI\Exceptions;

/** Lỗi tạm thời (5xx, timeout): job thử lại với backoff. */
class TransientProviderException extends ProviderException
{
    public function errorCode(): string
    {
        return 'PROVIDER_UNAVAILABLE';
    }
}
