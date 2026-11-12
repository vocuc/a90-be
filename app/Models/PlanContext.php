<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bối cảnh cố định của bài kế hoạch: chủ shop quy ước (nền, ánh sáng, người mẫu, phong cách...), planner chỉ đọc.
 * Ảnh kế hoạch = ảnh sản phẩm + prompt "mô tả bối cảnh + câu lệnh của planner", tạo bằng model + tỉ lệ khung ở đây.
 */
class PlanContext extends Model
{
    public const NAME_MAX = 100;

    public const DESCRIPTION_MAX = 4000;

    protected $guarded = ['id'];

    protected $attributes = ['aspect_ratio' => '9:16', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Prompt gửi worker cho 1 ảnh: bối cảnh trước, câu lệnh của planner sau. */
    public function prompt(string $itemPrompt): string
    {
        return trim($this->description)."\n\n".trim($itemPrompt);
    }
}
