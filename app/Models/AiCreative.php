<?php

namespace App\Models;

use App\Enums\CreativeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiCreative extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CreativeStatus::class,
            'is_public' => 'boolean',
            'hidden_at' => 'datetime',
            'published_at' => 'datetime',
            'rating_avg' => 'decimal:2',
        ];
    }

    /** Creative đang hiển thị trên Marketplace (đang bán, public, không bị Admin ẩn). */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('status', CreativeStatus::Published)->where('is_public', true)->whereNull('hidden_at');
    }

    /** Creative đang bán mà $user dùng được: Creative trên Marketplace, hoặc Creative private của chính $user. */
    public function scopeAvailableTo(Builder $query, ?User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->listed()->when($user, fn (Builder $q) => $q->orWhere(
            fn (Builder $q) => $q->where('creator_id', $user->id)->where('status', CreativeStatus::Published)->whereNull('hidden_at'),
        )));
    }

    public function isListed(): bool
    {
        return $this->status === CreativeStatus::Published && $this->is_public && $this->hidden_at === null;
    }

    public function isAvailableTo(User $user): bool
    {
        return $this->isListed()
            || ($this->creator_id === $user->id && $this->status === CreativeStatus::Published && $this->hidden_at === null);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function config(): HasOne
    {
        return $this->hasOne(AiCreativeConfig::class, 'creative_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(AiCreativeImage::class, 'creative_id')->orderBy('sort_order')->orderBy('id');
    }

    /** Nguyên liệu ẩn (ảnh của các trường hidden_image). */
    public function assets(): HasMany
    {
        return $this->hasMany(AiCreativeAsset::class, 'creative_id');
    }

    /** Hashtag theo thứ tự Creator nhập. */
    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'ai_creative_hashtag', 'creative_id', 'hashtag_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class, 'creative_id');
    }

    /** Nhóm tạo lô tự động chứa Creative (tối đa một nhóm). */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CreativeGroup::class, 'creative_group_items', 'creative_id', 'creative_group_id');
    }
}
