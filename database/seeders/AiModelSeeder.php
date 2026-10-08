<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Seeder;

/**
 * Danh sách model do dev quản lý. Creator chỉ được chọn trong danh sách này.
 */
class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        $gemini = AiProvider::query()->updateOrCreate(['code' => 'gemini'], ['name' => 'Google Gemini', 'is_active' => true]);

        AiModel::query()->updateOrCreate(
            ['provider_id' => $gemini->id, 'code' => 'gemini-2.5-flash-image'],
            [
                'name' => 'Gemini 2.5 Flash Image',
                'supported_aspect_ratios' => ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
                'is_active' => true,
            ],
        );
    }
}
