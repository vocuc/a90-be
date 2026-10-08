<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

abstract class ProviderException extends RuntimeException
{
    /** Mã lỗi lưu vào generation_outputs.error_code. */
    abstract public function errorCode(): string;
}
