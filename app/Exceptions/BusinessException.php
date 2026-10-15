<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Lỗi nghiệp vụ có mã lỗi ổn định để frontend xử lý.
 */
class BusinessException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function insufficientCredit(int $required, int $balance): self
    {
        return new self('INSUFFICIENT_CREDIT', 'Số dư Credit không đủ.', 402, [
            'required' => $required,
            'balance' => $balance,
        ]);
    }

    public static function creativeNotAvailable(): self
    {
        return new self('CREATIVE_NOT_AVAILABLE', 'AI Creative không tồn tại hoặc không còn hoạt động.', 404);
    }

    public static function creativeTemporarilyUnavailable(): self
    {
        return new self('CREATIVE_TEMPORARILY_UNAVAILABLE', 'AI Creative tạm thời không thể tạo ảnh. Vui lòng thử lại sau.', 409);
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_CONFLICT', 'idempotency_key đã được dùng cho một yêu cầu khác.', 409);
    }

    public static function notPublishable(array $reasons): self
    {
        return new self('CREATIVE_NOT_PUBLISHABLE', 'AI Creative chưa đủ điều kiện để đăng.', 422, ['reasons' => $reasons]);
    }

    public static function invalidState(string $message): self
    {
        return new self('INVALID_STATE', $message, 409);
    }

    public static function postNotEditable(): self
    {
        return new self('POST_NOT_EDITABLE', 'Bài đăng không còn ở trạng thái chờ gửi.', 409);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => $this->context ?: null,
        ]), $this->status);
    }
}
