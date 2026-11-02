<?php

namespace App\Models;

use App\Enums\OutputStatus;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Máy nội bộ, có loại (note/quan-ly-worker.txt ở repo frontend), gắn nhiều tài khoản A51 (users, admin gắn ở trang
 * Worker); một tài khoản có nhiều worker cùng loại:
 * - image: nhận job tạo ảnh của các tài khoản được gắn (GET /api/v1/jobs).
 * - publish: chủ shop (tài khoản được gắn) chọn ở trang bài đăng, nhận bài được giao (GET /api/v1/post-jobs,
 *   note/gop-luong-dang-bai.txt); lấy ảnh tạo video / đồng bộ template (GET /api/v1/video-images,
 *   PUT /api/v1/video-templates/sync).
 * Đổi loại / bỏ tài khoản bị chặn khi worker còn việc dở (WorkerAssignment).
 */
class Worker extends Model
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_PUBLISH = 'publish';

    public const TYPES = [self::TYPE_IMAGE, self::TYPE_PUBLISH];

    protected $guarded = ['id'];

    protected $attributes = [
        'type' => self::TYPE_PUBLISH,
    ];

    /** Worker đang bật được gắn cho tài khoản $userId; $type: chỉ worker loại đó. */
    public function scopeAssignedTo(Builder $query, int $userId, ?string $type = null): Builder
    {
        return $query->where('is_active', true)
            ->when($type, fn (Builder $q) => $q->where('type', $type))
            ->whereHas('users', fn (Builder $u) => $u->whereKey($userId));
    }

    /**
     * Worker tạo ảnh người dùng chọn khi tạo ảnh / tạo lô (note/chon-worker-tao-anh.txt ở repo frontend): phải là worker
     * loại image đang bật được gắn cho tài khoản, sai -> 422 errors["worker_id"]. null (không gửi) -> null.
     */
    public static function imageWorkerIdFor(int $userId, mixed $workerId): ?int
    {
        if ($workerId === null || $workerId === '') {
            return null;
        }

        $valid = self::query()->assignedTo($userId, self::TYPE_IMAGE)->whereKey((int) $workerId)->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['worker_id' => self::query()->assignedTo($userId, self::TYPE_IMAGE)->exists()
                ? 'Worker tạo ảnh không còn được gắn cho tài khoản, chọn worker khác.'
                : 'Tài khoản chưa được gắn worker tạo ảnh, liên hệ A51 để được gắn.']);
        }

        return (int) $workerId;
    }

    public static function typeLabel(string $type): string
    {
        return $type === self::TYPE_IMAGE ? 'Tạo ảnh' : 'Đăng bài';
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    public function isPublish(): bool
    {
        return $this->type === self::TYPE_PUBLISH;
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
    public static function register(string $name, string $type = self::TYPE_IMAGE): array
    {
        $token = Str::random(64);
        $worker = self::query()->create([
            'name' => $name,
            'type' => $type,
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

    /**
     * Tài khoản A51 được gắn (admin gắn ở trang Worker). image: nhận job tạo ảnh của các tài khoản này; publish: các shop
     * chọn được worker để đăng bài. Chưa gắn tài khoản nào -> không nhận việc.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'worker_user')->withPivot('created_at');
    }

    /**
     * Số việc worker đang dở (chặn đổi loại / bỏ tài khoản): image: ảnh worker đã nhận chưa trả; publish: bài được giao
     * đang chờ đăng hoặc đang đăng. $userId: chỉ việc của tài khoản đó.
     */
    public function pendingJobCount(?int $userId = null): int
    {
        if ($this->isImage()) {
            return GenerationOutput::query()
                ->where('worker_id', $this->id)
                ->where('status', OutputStatus::Processing)
                ->when($userId, fn ($q) => $q->whereHas('generation', fn ($g) => $g->where('user_id', $userId)))
                ->count();
        }

        return $this->posts()
            ->whereIn('status', [SocialPostStatus::Queued, SocialPostStatus::Publishing])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->count();
    }

    /** Worker gọi API không đúng loại của nó (vd worker tạo ảnh gọi /post-jobs): 409 WORKER_TYPE_MISMATCH. */
    public function assertType(string $type): void
    {
        if ($this->type !== $type) {
            throw new BusinessException(
                'WORKER_TYPE_MISMATCH',
                "Worker \"{$this->name}\" là loại ".self::typeLabel($this->type).', không làm việc '.self::typeLabel($type).'.',
                409,
            );
        }
    }

    /** Bài đăng được giao cho worker (loại publish). */
    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }
}
