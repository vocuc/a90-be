<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $names = ['Thời trang', 'Mỹ phẩm', 'Đồ ăn & Đồ uống', 'Nội thất', 'Điện tử', 'Trang sức', 'Khác'];

        foreach ($names as $order => $name) {
            Category::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'sort_order' => $order]);
        }
    }
}
