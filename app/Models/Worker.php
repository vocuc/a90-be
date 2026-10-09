<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Máy tạo ảnh nội bộ: lấy việc qua GET /api/v1/jobs, trả ảnh qua POST /api/v1/jobs/{id}.
 */
class Worker extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Tạo worker mới, trả về [worker, token]. Token chỉ có ở đây, DB chỉ lưu hash. */
    public static function register(string $name): array
    {
        $token = Str::random(64);
        $worker = self::query()->create(['name' => $name, 'token_hash' => self::hashToken($token)]);

        return [$worker, $token];
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
}
