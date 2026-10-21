<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Nhóm creative của Creator cho tạo lô tự động: mỗi lô = 1 sản phẩm x các creative của 1 nhóm. */
class CreativeGroup extends Model
{
    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Creative theo thứ tự người dùng chọn; một creative chỉ ở một nhóm (unique creative_id). */
    public function creatives(): BelongsToMany
    {
        return $this->belongsToMany(AiCreative::class, 'creative_group_items', 'creative_group_id', 'creative_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }
}
