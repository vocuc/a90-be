<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreativeConfig extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'creative_prompt' => 'array',
            'allowed_aspect_ratios' => 'array',
            'extra_params' => 'array',
        ];
    }

    public function creative(): BelongsTo
    {
        return $this->belongsTo(AiCreative::class, 'creative_id');
    }
}
