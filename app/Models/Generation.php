<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Generation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['prompt_snapshot'];

    protected function casts(): array
    {
        return [
            'type' => GenerationType::class,
            'status' => GenerationStatus::class,
            'completed_at' => 'datetime',
            'input_paths' => 'array',
            'asset_paths' => 'array',
            'input_values' => 'array',
            'insert_logo' => 'boolean',
        ];
    }

    public function isPaid(): bool
    {
        return $this->type === GenerationType::Paid;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function creative(): BelongsTo
    {
        return $this->belongsTo(AiCreative::class, 'creative_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'parent_generation_id');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(GenerationOutput::class)->orderBy('position');
    }
}
