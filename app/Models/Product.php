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

    /** Độ dài tối đa của "Yêu cầu" (prompt_note), nối vào cuối prompt nên giữ ngắn so với prompt Creative. */
    public const PROMPT_NOTE_MAX = 2000;

    protected $casts = ['is_active' => 'boolean'];

    /**
     * Nối "Yêu cầu" của sản phẩm vào cuối prompt đã render. Ảnh không gắn sản phẩm (0 / null), sản phẩm đã xoá
     * hoặc chưa có yêu cầu -> giữ nguyên prompt.
     */
    public static function appendPromptNote(string $prompt, ?int $productId): string
    {
        $note = $productId ? trim((string) static::query()->whereKey($productId)->value('prompt_note')) : '';

        return $note === '' ? $prompt : trim($prompt."\n\n".$note);
    }

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

    /** Sản phẩm đang hoạt động, chỉ những sản phẩm này được tạo lô tự động. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * product_id người dùng gửi khi tạo ảnh (lưu vào generations.product_id): rỗng -> null,
     * không phải sản phẩm của người dùng -> 422 errors[$field].
     */
    public static function resolveOwnedId(User $user, mixed $productId, string $field = 'product_id'): ?int
    {
        $productId = $productId ? (int) $productId : null;
        if ($productId && ! static::query()->ownedBy($user)->whereKey($productId)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Sản phẩm không tồn tại.']);
        }

        return $productId;
    }

    /**
     * Cột generated_image_count = số ảnh đã tạo xong cho sản phẩm (generations.product_id), không tính ảnh lỗi /
     * đang tạo / người dùng đã tắt. Gọi sau withAutoBatchCount (scope đó đặt lại select).
     */
    public function scopeWithGeneratedImageCount(Builder $query): Builder
    {
        return $query->addSelect(['generated_image_count' => DB::table('generation_outputs')
            ->join('generations', 'generations.id', '=', 'generation_outputs.generation_id')
            ->whereColumn('generations.product_id', 'products.id')
            ->where('generation_outputs.status', 'completed')
            ->whereNotNull('generation_outputs.image_path')
            ->whereNull('generation_outputs.disabled_at')
            ->selectRaw('COUNT(*)')]);
    }

    /** Cột auto_batch_count = số lần chạy tự động của $user với sản phẩm. */
    public function scopeWithAutoBatchCount(Builder $query, User $user): Builder
    {
        return $query->select('products.*')->addSelect(['auto_batch_count' => DB::table('auto_batch_product_counts')
            ->select('count')
            ->whereColumn('product_id', 'products.id')
            ->where('user_id', $user->id)]);
    }
}
