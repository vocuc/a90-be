<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorProfile extends Model
{
    protected $guarded = ['id'];

    // Token GHN chỉ dùng ở backend, không bao giờ trả về frontend
    protected $hidden = ['ghn_token'];

    protected function casts(): array
    {
        return [
            'ghn_token' => 'encrypted',
            'social_links' => 'array',
            'verified' => 'boolean',
            'rating_avg' => 'decimal:2',
        ];
    }

    public function hasGhnSettings(): bool
    {
        return $this->ghn_token && $this->ghn_shop_id;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
