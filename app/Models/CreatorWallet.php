<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorWallet extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pending_balance' => 'integer',
            'available_balance' => 'integer',
            'withdrawable_balance' => 'integer',
            'total_earned' => 'integer',
        ];
    }
}
