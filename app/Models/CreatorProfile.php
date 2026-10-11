<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'verified' => 'boolean',
            'creator_share_percent' => 'decimal:2',
            'rating_avg' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
