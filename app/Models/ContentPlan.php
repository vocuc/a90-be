<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kỳ kế hoạch N ngày của kênh (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.5). Tạo khi lệnh /lap-ke-hoach
 * lấy context (status preparing, giữ các slot giờ backend đã tính); nhận bài -> draft (hoặc duyệt luôn nếu kênh bật
 * tự duyệt kế hoạch). Duyệt -> mỗi bài kế hoạch thành 1 bài đăng.
 */
class ContentPlan extends Model
{
    /** Đã cấp slot cho lệnh, chưa nhận bài nào. Hết hạn sau SLOT_HOLD_HOURS. */
    public const PREPARING = 'preparing';

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    public const SLOT_HOLD_HOURS = 24;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'slots' => 'array',
            'warnings' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function slot(int $index): ?array
    {
        return collect($this->slots)->firstWhere('index', $index);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentPlanItem::class, 'plan_id')->orderBy('scheduled_at');
    }
}
