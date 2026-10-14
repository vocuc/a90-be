<?php

namespace App\Services\TikTok;

use RuntimeException;

/**
 * TikTok trả lỗi nghiệp vụ (error.code khác "ok", hoặc error của OAuth) — khác với lỗi mạng / 5xx (TIKTOK_UNAVAILABLE).
 */
class TikTokApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
