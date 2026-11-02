<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Máy nội bộ: tạo ảnh (GET /api/v1/jobs, các tài khoản tạo ảnh imageUsers),
 * đăng bài ảnh / video được giao (GET /api/v1/post-jobs, note/gop-luong-dang-bai.txt ở repo frontend),
 * lấy ảnh tạo video / đồng bộ template (GET /api/v1/video-images, PUT /api/v1/video-templates/sync).
 * video_user_id: shop worker đăng bài + tạo video cho (admin gắn ở trang Worker, mục "Shop đăng bài").
 */
class Worker extends Model
{
    protected $guarded = ['id'];

    /**
     * Worker đang bật được gắn cho tài khoản $userId (note/quan-ly-worker.txt ở repo frontend): tạo ảnh cho tài khoản, hoặc
     * đăng bài + tạo video cho tài khoản (video_user_id).
     */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('is_active', true)->where(fn (Builder $q) => $q
            ->whereHas('imageUsers', fn (Builder $u) => $u->whereKey($userId))
            ->orWhere('video_user_id', $userId));
    }

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

    /** Tài khoản A51 worker nhận job tạo ảnh (admin gắn ở trang Worker). Chưa gắn tài khoản nào -> không nhận job ảnh. */
    public function imageUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'worker_user')->withPivot('created_at');
    }

    /** Tài khoản A51 worker tạo video lấy ảnh (admin gắn ở trang Worker, tối đa 1). Chưa gắn -> không lấy được ảnh. */
    public function videoUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'video_user_id');
    }
}
