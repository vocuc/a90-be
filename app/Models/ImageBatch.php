<?php

namespace App\Models;

use App\Enums\OutputStatus;
use App\Services\ImageBatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Lô ảnh: tạo một lần từ nhiều mẫu, sau đó chỉ tạo lại / sửa nguyên liệu / xoá từng ảnh. */
class ImageBatch extends Model
{
    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ảnh theo thứ tự tạo, ảnh đầu là ảnh bìa. */
    public function images(): HasMany
    {
        return $this->hasMany(ImageBatchImage::class)->orderBy('position')->orderBy('id');
    }

    /** Ảnh bìa: ảnh đã xong đứng đầu (dùng cho danh sách, không load hết ảnh). */
    public function coverImage(): HasOne
    {
        return $this->hasOne(ImageBatchImage::class)->ofMany(['position' => 'min'], fn (Builder $q) => $q
            ->whereHas('output', fn (Builder $o) => $o->where('status', OutputStatus::Completed)));
    }

    /** Số ảnh / đang tạo / lỗi / số bài TikTok + ảnh bìa cho danh sách lô. */
    public function scopeWithSummary(Builder $query): Builder
    {
        return $query
            ->with('coverImage.output')
            ->withCount([
                'images',
                'images as generating_count' => fn (Builder $q) => $q->whereHas('output', fn (Builder $o) => $o->whereIn('status', ImageBatchService::BUSY)),
                'images as failed_count' => fn (Builder $q) => $q->whereHas('output', fn (Builder $o) => $o->where('status', OutputStatus::Failed)),
                'posts',
            ]);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(TikTokPost::class, 'image_batch_id');
    }
}
