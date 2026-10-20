<?php

namespace App\Models;

use App\Enums\PublishDriver;
use App\Enums\SocialPlatform;
use App\Enums\TikTokConnectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tài khoản đăng bài của một tài khoản A51 (user_id), tối đa 1 dòng / nền tảng / driver:
 * - driver worker: admin gắn với 1 worker (máy đã đăng nhập sẵn tài khoản TikTok / Facebook / Instagram), mỗi worker
 *   giữ 1 tài khoản. Worker chỉ nhận job của nền tảng và tài khoản A51 được gắn.
 * - driver api (chỉ TikTok): người dùng tự kết nối TikTok bằng OAuth. Token mã hoá, không bao giờ trả ra API hay ghi log.
 */
class PublishAccount extends Model
{
    public const TIKTOK_REQUIRED_SCOPE = 'video.upload';

    protected $table = 'publish_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'driver' => PublishDriver::class,
            'is_active' => 'boolean',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'connection_status' => TikTokConnectionStatus::class,
            'last_error' => 'array',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public static function hasRequiredScope(?string $scope): bool
    {
        return in_array(self::TIKTOK_REQUIRED_SCOPE, array_map('trim', explode(',', (string) $scope)), true);
    }

    public function isApi(): bool
    {
        return $this->driver === PublishDriver::Api;
    }

    /** Tài khoản api còn gọi được TikTok. */
    public function isUsable(): bool
    {
        return $this->connection_status === TikTokConnectionStatus::Active
            && $this->refresh_token !== null
            && self::hasRequiredScope($this->scope);
    }

    /** Lý do không dùng được (expired | revoked | scope_missing), null nếu dùng được. */
    public function connectionReason(): ?string
    {
        return match (true) {
            $this->connection_status === TikTokConnectionStatus::Revoked => 'revoked',
            $this->connection_status !== TikTokConnectionStatus::Active || $this->refresh_token === null => 'expired',
            ! self::hasRequiredScope($this->scope) => 'scope_missing',
            default => null,
        };
    }

    /** Tài khoản A51 được đăng bài qua tài khoản này. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(SocialPublishJob::class, 'publish_account_id');
    }
}
