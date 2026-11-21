<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chủ đề CỤ THỂ của kênh ("Đi chúc Tết", "Đi làm cả ngày không đau chân"), dùng chung mọi loại bài. AI tự tạo khi lập kế
 * hoạch (không cần duyệt), chủ shop sửa / tạm tắt được. Bài đăng ghi topic_id để sau thống kê chủ đề nào được quan tâm.
 * a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt mục 4.
 */
class ChannelTopic extends Model
{
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const NAME_MAX = 80;

    /** Cùng chủ đề + cùng loại bài không lặp trong số ngày này (trước / sau giờ đăng). */
    public const REPEAT_DAYS = 14;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public static function cleanName(?string $name): string
    {
        return trim(preg_replace(['/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '/\s+/u'], ['', ' '], (string) $name));
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class, 'topic_id');
    }
}
