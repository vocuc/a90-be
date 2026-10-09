<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            AiModelSeeder::class,
            CategorySeeder::class,
            AdminSeeder::class,
        ]);
    }
}
