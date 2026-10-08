<?php

namespace Tests\Feature;

use App\Models\Category;
use Tests\TestCase;

/** TestCase đã seed sẵn 7 danh mục của CategorySeeder. */
class CategoryTest extends TestCase
{
    public function test_categories_include_icon_and_hide_inactive(): void
    {
        Category::query()->where('slug', 'khac')->update(['is_active' => false]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.slug', 'thoi-trang')
            ->assertJsonPath('data.0.icon', 'styler')
            ->assertJsonPath('data.2.slug', 'do-an-do-uong')
            ->assertJsonPath('data.2.icon', 'restaurant')
            ->assertJsonMissing(['slug' => 'khac']);
    }

    public function test_icon_is_null_when_not_set(): void
    {
        Category::query()->create(['name' => 'Ô tô', 'slug' => 'o-to', 'sort_order' => 99]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.7.slug', 'o-to')
            ->assertJsonPath('data.7.icon', null);
    }
}
