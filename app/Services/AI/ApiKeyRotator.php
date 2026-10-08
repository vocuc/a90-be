<?php

namespace App\Services\AI;

use App\Enums\ApiKeyStatus;
use App\Models\CreatorApiKey;
use App\Services\SettingService;
use Illuminate\Support\Str;

/**
 * Chọn key của Creator theo vòng tròn (key ít được dùng gần đây nhất trước).
 */
class ApiKeyRotator
{
    public function __construct(private SettingService $settings) {}

    /** @param  int[]  $excludeIds  các key đã thử trong lần gọi này */
    public function next(int $userId, int $providerId, array $excludeIds = []): ?CreatorApiKey
    {
        $key = CreatorApiKey::query()
            ->where('user_id', $userId)
            ->where('provider_id', $providerId)
            ->where('status', ApiKeyStatus::Active)
            ->where(fn ($q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()))
            ->whereNotIn('id', $excludeIds)
            ->orderBy('last_used_at')
            ->orderBy('id')
            ->first();

        $key?->forceFill(['last_used_at' => now()])->save();

        return $key;
    }

    /** Creator còn key active (kể cả đang cooldown) cho Provider này không. */
    public function hasActiveKey(int $userId, int $providerId): bool
    {
        return CreatorApiKey::query()
            ->where('user_id', $userId)
            ->where('provider_id', $providerId)
            ->where('status', ApiKeyStatus::Active)
            ->exists();
    }

    public function markInvalid(CreatorApiKey $key, string $error): void
    {
        $key->forceFill([
            'status' => ApiKeyStatus::Invalid,
            'last_error' => Str::limit($error, 250),
        ])->save();
    }

    public function markCooldown(CreatorApiKey $key, string $error): void
    {
        $key->forceFill([
            'cooldown_until' => now()->addMinutes($this->settings->int('api_key.cooldown_minutes')),
            'last_error' => Str::limit($error, 250),
        ])->save();
    }
}
