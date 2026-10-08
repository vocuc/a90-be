<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('ai90.defaults') as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }
}
