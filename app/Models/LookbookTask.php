<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần tạo lookbook cho một sản phẩm (skill FlowKit tao-anh-look-book): A51 giao sản phẩm (processing), skill báo
 * xong (completed, kèm bài đăng chờ duyệt) hoặc lỗi (failed). Đây là lịch sử để chọn sản phẩm tiếp theo và biết
 * outfit nào đã dùng cho sản phẩm; số sản phẩm mỗi ngày do skill tự cấu hình, A51 không giữ.
 */
class LookbookTask extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Task processing quá lâu (skill chết giữa chừng): giao lại sản phẩm đó được. */
    public const STALE_MINUTES = 120;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'outfit_ids' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }
}
