<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            AiModelSeeder::class,
            CategorySeeder::class,
        ]);

        // Tài khoản admin để phát triển local. Đổi mật khẩu trước khi lên production.
        if (! app()->isProduction()) {
            $admin = User::query()->firstOrCreate(
                ['email' => env('ADMIN_EMAIL', 'admin@a90.local')],
                ['name' => 'Admin', 'password' => env('ADMIN_PASSWORD', 'password')],
            );
            $admin->forceFill(['role' => 'admin'])->save();
            $admin->creditWallet()->firstOrCreate([]);
        }
    }
}
