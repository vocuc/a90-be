<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Sản phẩm của Creator cho tạo lô tự động: mỗi ảnh điền vào trường nguyên liệu có key = field_key. */
class Product extends Model
{
    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    /** Ảnh đầu tiên (ảnh bìa), không load hết ảnh. */
    public function coverImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(['position' => 'min', 'id' => 'min']);
    }

    /** Hashtag theo thứ tự nhập: tạo lô tự động chỉ ghép với nhóm có creative trùng hashtag. */
    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'product_hashtag')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
