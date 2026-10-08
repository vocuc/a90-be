<?php

namespace App\Models;

use App\Enums\ApiKeyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreatorApiKey extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    // Không bao giờ để key lọt ra ngoài khi serialize.
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'status' => ApiKeyStatus::class,
            'cooldown_until' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
