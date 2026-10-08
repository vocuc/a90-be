<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // Tên danh mục => icon Material Symbols
        $categories = [
            'Thời trang' => 'styler',
            'Mỹ phẩm' => 'spa',
            'Đồ ăn & Đồ uống' => 'restaurant',
            'Nội thất' => 'chair',
            'Điện tử' => 'devices',
            'Trang sức' => 'diamond',
            'Khác' => 'category',
        ];

        $order = 0;
        foreach ($categories as $name => $icon) {
            Category::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'icon' => $icon, 'sort_order' => $order++],
            );
        }
    }
}
