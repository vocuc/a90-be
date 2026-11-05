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
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTO = 'auto';

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

    /**
     * Cột ảo product_id: sản phẩm của lô = generations.product_id của ảnh đầu tiên có gắn sản phẩm (lô không lưu sản phẩm).
     * Phải gọi trước khi eager load quan hệ product.
     */
    public function scopeWithProductId(Builder $query): Builder
    {
        return $query->addSelect(['product_id' => Generation::query()
            ->select('generations.product_id')
            ->join('image_batch_images', 'image_batch_images.generation_id', '=', 'generations.id')
            ->whereColumn('image_batch_images.image_batch_id', 'image_batches.id')
            ->where('generations.product_id', '>', 0)
            ->orderBy('image_batch_images.position')
            ->limit(1),
        ]);
    }

    /** Số ảnh / đang tạo / lỗi / số bài đăng + ảnh bìa cho danh sách lô. */
    public function scopeWithSummary(Builder $query): Builder
    {
        return $query
            ->withProductId()
            ->with(['coverImage.output', 'product.coverImage', 'group'])
            ->withCount([
                'images',
                'images as generating_count' => fn (Builder $q) => $q->whereHas('output', fn (Builder $o) => $o->whereIn('status', ImageBatchService::BUSY)),
                'images as failed_count' => fn (Builder $q) => $q->whereHas('output', fn (Builder $o) => $o->where('status', OutputStatus::Failed)),
                'posts',
            ]);
    }

    /** Sản phẩm của lô theo cột ảo product_id (cần scope withProductId); null khi ảnh không gắn sản phẩm / sản phẩm đã xoá. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lô tạo tự động (source = auto): nhóm creative đã dùng, null khi lô tạo tay hoặc nhóm đã xoá. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CreativeGroup::class, 'creative_group_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class, 'image_batch_id');
    }
}
