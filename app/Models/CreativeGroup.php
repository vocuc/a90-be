<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Nhóm creative của Creator cho tạo lô tự động: mỗi lô = 1 sản phẩm x các creative của 1 nhóm.
 * channel_id null = dùng chung mọi kênh; có giá trị = chỉ dùng cho kênh đó (xoá kênh thì nhóm thành dùng chung).
 */
class CreativeGroup extends Model
{
    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Nhóm dùng được cho kênh: nhóm dùng chung + nhóm riêng của kênh. Không theo kênh (null): chỉ nhóm dùng chung. */
    public function scopeForChannel(Builder $query, ?int $channelId): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('channel_id')
            ->when($channelId, fn (Builder $q) => $q->orWhere('channel_id', $channelId)));
    }

    /** Creative theo thứ tự người dùng chọn; một creative chỉ ở một nhóm (unique creative_id). */
    public function creatives(): BelongsToMany
    {
        return $this->belongsToMany(AiCreative::class, 'creative_group_items', 'creative_group_id', 'creative_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }
}
