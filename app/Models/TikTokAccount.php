<?php

namespace App\Models;

use App\Enums\TikTokAccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tài khoản TikTok người dùng đã ủy quyền. Token mã hoá khi lưu, không bao giờ trả ra API hay ghi log.
 */
class TikTokAccount extends Model
{
    public const REQUIRED_SCOPE = 'video.upload';

    protected $table = 'tiktok_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'status' => TikTokAccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'last_error' => 'array',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public static function hasRequiredScope(?string $scope): bool
    {
        return in_array(self::REQUIRED_SCOPE, array_map('trim', explode(',', (string) $scope)), true);
    }

    public function isUsable(): bool
    {
        return $this->status === TikTokAccountStatus::Active && $this->refresh_token !== null && self::hasRequiredScope($this->scope);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
