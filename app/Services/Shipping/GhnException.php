<?php

namespace App\Services\Shipping;

use RuntimeException;

/** GHN trả lỗi hoặc không kết nối được. message là lời báo của GHN, hiển thị thẳng cho người dùng. */
class GhnException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?string $codeMessage = null,
    ) {
        parent::__construct($message);
    }

    /** Token sai / hết hạn (GHN trả 401). */
    public function isUnauthorized(): bool
    {
        return $this->httpStatus === 401;
    }
}
