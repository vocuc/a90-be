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

    public static function planProductUnavailable(): self
    {
        return new self('PLAN_PRODUCT_UNAVAILABLE', 'Sản phẩm không tồn tại, đã ngừng bán hoặc chưa có ảnh.', 422);
    }

    public static function planContextUnavailable(): self
    {
        return new self('PLAN_CONTEXT_UNAVAILABLE', 'Bối cảnh không tồn tại hoặc đã tắt.', 422);
    }

    public static function planPostNotRedoable(): self
    {
        return new self('PLAN_POST_NOT_REDOABLE', 'Chỉ làm lại được bài kế hoạch đang chờ duyệt. Bài đã duyệt thì bấm "Bỏ duyệt" trước.', 409);
    }

    public static function planPostNotApproved(): self
    {
        return new self('PLAN_POST_NOT_APPROVED', 'Bài kế hoạch chưa được duyệt, hãy bấm "Duyệt" trước khi đăng.', 409);
    }

    public static function planPostNotApprovable(string $message): self
    {
        return new self('PLAN_POST_NOT_APPROVABLE', $message, 409);
    }

    public static function postNotEditable(): self
    {
        return new self('POST_NOT_EDITABLE', 'Bài đăng không còn ở trạng thái chờ gửi.', 409);
    }

    public static function ghnSettingsRequired(): self
    {
        return new self('GHN_SETTINGS_REQUIRED', 'Bạn cần kết nối GHN (Token, ShopId) trong Quản lý cửa hàng trước.', 400);
    }

    /** GHN trả lỗi: message là lời báo của GHN. */
    public static function ghnError(string $message): self
    {
        return new self('GHN_ERROR', $message, 422);
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
