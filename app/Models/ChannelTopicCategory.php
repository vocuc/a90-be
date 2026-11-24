<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nhóm chủ đề của KÊNH (người dùng chốt 2026-11-24): chủ shop thêm / đổi tên / xoá / sắp thứ tự ở tab Chủ đề. Kênh chưa có
 * nhóm nào thì tạo sẵn ChannelTopic::DEFAULT_CATEGORIES. Chủ đề không thuộc nhóm nào (category_id null) hiện ở "Khác";
 * xoá nhóm -> chủ đề của nhóm về "Khác" (FK nullOnDelete).
 */
class ChannelTopicCategory extends Model
{
    public const NAME_MAX = 40;

    /** Tối đa số nhóm / kênh. */
    public const MAX = 20;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public static function cleanName(?string $name): string
    {
        return ChannelTopic::cleanName($name);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(ChannelTopic::class, 'category_id');
    }
}
