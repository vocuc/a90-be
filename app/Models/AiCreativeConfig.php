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
            'input_schema' => 'array',
        ];
    }

    public function creative(): BelongsTo
    {
        return $this->belongsTo(AiCreative::class, 'creative_id');
    }
}
