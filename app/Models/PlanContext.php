<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bối cảnh mẫu của bài kế hoạch: chủ shop ghi yêu cầu (nền, ánh sáng, bộ ảnh bắt buộc, câu phải thêm vào prompt...),
 * planner đọc mô tả làm quy tắc rồi tự viết prompt đầy đủ cho từng ảnh. Mô tả KHÔNG được ghép vào prompt gửi worker.
 * Ảnh kế hoạch = ảnh sản phẩm + prompt của planner, tạo bằng model + tỉ lệ khung ở đây.
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

    /**
     * Prompt gửi worker cho 1 ảnh: đúng câu lệnh planner viết. Mô tả bối cảnh là yêu cầu cho planner, không ghép vào
     * (trong đó có cả các dòng chỉ dành cho planner như "bộ ảnh bắt buộc", "câu phải thêm vào cuối").
     */
    public function prompt(string $itemPrompt): string
    {
        return trim($itemPrompt);
    }
}
