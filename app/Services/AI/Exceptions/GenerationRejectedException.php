<?php

namespace App\Services\AI\Exceptions;

/** Provider từ chối (safety, ảnh/prompt không hợp lệ, không trả ảnh): thất bại luôn, không thử lại. */
class GenerationRejectedException extends ProviderException
{
    public function __construct(string $message, private string $rejectCode = 'GENERATION_REJECTED')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->rejectCode;
    }
}
