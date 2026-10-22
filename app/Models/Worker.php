<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Máy nội bộ: tạo ảnh (GET /api/v1/jobs), viết nội dung (GET /api/v1/content-jobs),
 * đăng bài (GET /api/v1/publish-jobs, chỉ worker được gắn tài khoản đăng bài của một nền tảng).
 */
class Worker extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'token_encrypted' => 'encrypted',
        ];
    }

    /**
     * Tạo worker mới, trả về [worker, token]. DB lưu hash (xác thực) + bản mã hoá (admin xem lại ở trang Worker).
     */
    public static function register(string $name): array
    {
        $token = Str::random(64);
        $worker = self::query()->create([
            'name' => $name,
            'token_hash' => self::hashToken($token),
            'token_encrypted' => $token,
        ]);

        return [$worker, $token];
    }

    /** Đổi token: token cũ hết hiệu lực ngay (máy đang chạy token cũ bị 401). Trả về token mới. */
    public function regenerateToken(): string
    {
        $token = Str::random(64);
        $this->forceFill(['token_hash' => self::hashToken($token), 'token_encrypted' => $token])->save();

        return $token;
    }

    /** Token để admin xem lại; null với worker tạo trước khi lưu token mã hoá. */
    public function plainToken(): ?string
    {
        return $this->token_encrypted;
    }

    public static function findActiveByToken(?string $token): ?self
    {
        if (! $token) {
            return null;
        }

        return self::query()->where('token_hash', self::hashToken($token))->where('is_active', true)->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Tài khoản đăng bài worker đang giữ (admin gắn ở trang Tài khoản đăng bài): quyết định nền tảng worker đăng. */
    public function publishAccount(): HasOne
    {
        return $this->hasOne(PublishAccount::class);
    }

    /** Tài khoản A51 worker nhận job tạo ảnh (admin gắn ở trang Worker). Chưa gắn tài khoản nào -> không nhận job ảnh. */
    public function imageUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'worker_user')->withPivot('created_at');
    }
}
