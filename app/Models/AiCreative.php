<?php

namespace App\Models;

use App\Enums\CreativeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiCreative extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CreativeStatus::class,
            'hidden_at' => 'datetime',
            'published_at' => 'datetime',
            'rating_avg' => 'decimal:2',
        ];
    }

    /** Creative đang hiển thị trên Marketplace. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('status', CreativeStatus::Published)->whereNull('hidden_at');
    }

    public function isListed(): bool
    {
        return $this->status === CreativeStatus::Published && $this->hidden_at === null;
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

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class, 'creative_id');
    }
}
