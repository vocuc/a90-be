<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Tạo (hoặc nâng quyền) tài khoản admin từ ADMIN_EMAIL / ADMIN_PASSWORD.
 * Chạy riêng: php artisan db:seed --class=AdminSeeder
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('ai90.admin.email');
        $password = config('ai90.admin.password');

        if (app()->isProduction() && (! $password || $password === 'password')) {
            throw new RuntimeException('Đặt ADMIN_PASSWORD đủ mạnh trong .env trước khi tạo admin trên production.');
        }

        // User đã tồn tại thì giữ nguyên mật khẩu, chỉ nâng quyền admin.
        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => config('ai90.admin.name'), 'password' => $password ?: 'password'],
        );
        $admin->forceFill(['role' => UserRole::Admin, 'status' => 'active'])->save();
        $admin->creditWallet()->firstOrCreate([]);

        $this->command?->info("Admin: {$email}");
    }
}
