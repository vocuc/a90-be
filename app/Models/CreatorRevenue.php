<?php

namespace App\Models;

use App\Enums\RevenueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorRevenue extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RevenueStatus::class,
            'available_at' => 'datetime',
            'released_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
