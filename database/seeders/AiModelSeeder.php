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
        $ratios = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];

        // code = tên model trong Gemini API (GET /v1beta/models)
        $models = [
            'gemini-2.5-flash-image' => 'Gemini 2.5 Flash Image',
            'gemini-3-pro-image' => 'Nano Banana Pro (Gemini 3 Pro Image)',
        ];

        foreach ($models as $code => $name) {
            AiModel::query()->updateOrCreate(
                ['provider_id' => $gemini->id, 'code' => $code],
                ['name' => $name, 'supported_aspect_ratios' => $ratios, 'is_active' => true],
            );
        }
    }
}
