<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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

    /** Hashtag theo thứ tự nhập (tạo lô tự động không xét hashtag). */
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

    /** Sản phẩm $user dùng được để tạo lô: của chính mình, nhân viên thì của shop chủ (note/chia-se-tai-nguyen.txt). */
    public function scopeUsableBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->resourceOwnerId());
    }

    /** Cột auto_batch_count = số lần chạy tự động của $user với sản phẩm (mỗi nhân viên đếm riêng). */
    public function scopeWithAutoBatchCount(Builder $query, User $user): Builder
    {
        return $query->select('products.*')->addSelect(['auto_batch_count' => DB::table('auto_batch_product_counts')
            ->select('count')
            ->whereColumn('product_id', 'products.id')
            ->where('user_id', $user->id)]);
    }
}
