<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use Tests\TestCase;

/** TestCase đã seed sẵn 7 danh mục của CategorySeeder (thoi-trang, my-pham, ...). */
class AdminCategoryTest extends TestCase
{
    private function admin(): User
    {
        $admin = $this->buyer();
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Giày dép', 'slug' => '', 'icon' => 'steps', 'sort_order' => 3, 'is_active' => '1'], $overrides);
    }

    public function test_non_admins_cannot_manage_categories(): void
    {
        $this->get('/admin/categories')->assertRedirect('/admin/login');
        $this->actingAs($this->buyer())->post('/admin/categories', $this->payload())->assertRedirect('/admin/login');
        $this->assertDatabaseMissing('categories', ['slug' => 'giay-dep']);
    }

    public function test_admin_lists_and_creates_category_with_auto_slug(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/categories')->assertOk()->assertSee('Thời trang')->assertSee('styler');
        $this->get('/admin/categories/create')->assertOk()->assertSee('Thêm danh mục');

        $this->post('/admin/categories', $this->payload(['icon' => ' Steps ']))
            ->assertRedirect('/admin/categories')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', ['name' => 'Giày dép', 'slug' => 'giay-dep', 'icon' => 'steps', 'sort_order' => 3, 'is_active' => true]);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.category.create')->exists());

        // Danh mục mới xuất hiện trên API công khai kèm icon
        $this->getJson('/api/v1/categories')->assertJsonFragment(['slug' => 'giay-dep', 'icon' => 'steps']);
    }

    public function test_validation_rejects_duplicate_slug_and_bad_icon(): void
    {
        $this->actingAs($this->admin());
        $before = Category::query()->count();

        // "Thời trang" đã có slug thoi-trang
        $this->post('/admin/categories', $this->payload(['name' => 'Thời trang']))->assertSessionHasErrors('slug');
        $this->post('/admin/categories', $this->payload(['icon' => 'bad icon!']))->assertSessionHasErrors('icon');
        $this->post('/admin/categories', $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->assertSame($before, Category::query()->count());
    }

    public function test_admin_updates_and_toggles_category(): void
    {
        $category = Category::query()->create(['name' => 'Giày', 'slug' => 'giay', 'icon' => 'steps']);
        $this->actingAs($this->admin());

        $this->get("/admin/categories/{$category->id}/edit")->assertOk()->assertSee('Lưu thay đổi');

        // Giữ nguyên slug của chính nó không bị báo trùng; icon để trống -> null
        $this->put("/admin/categories/{$category->id}", $this->payload(['name' => 'Giày thể thao', 'slug' => 'giay', 'icon' => '', 'sort_order' => 1]))
            ->assertSessionHasNoErrors();
        $category->refresh();
        $this->assertSame('Giày thể thao', $category->name);
        $this->assertNull($category->icon);

        $this->post("/admin/categories/{$category->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($category->refresh()->is_active);
        $this->getJson('/api/v1/categories')->assertJsonMissing(['slug' => 'giay']);

        $this->post("/admin/categories/{$category->id}/toggle");
        $this->assertTrue($category->refresh()->is_active);
        $this->getJson('/api/v1/categories')->assertJsonFragment(['slug' => 'giay']);
    }

    public function test_category_with_creatives_cannot_be_deleted(): void
    {
        $used = Category::query()->where('slug', 'my-pham')->firstOrFail();
        $empty = Category::query()->create(['name' => 'Ô tô', 'slug' => 'o-to']);
        $this->publishedCreative($this->creator())->update(['category_id' => $used->id]);
        $this->actingAs($this->admin());

        $this->get('/admin/categories')->assertOk()->assertSee('Đang có Creative, hãy tắt thay vì xóa');

        $this->delete("/admin/categories/{$used->id}")->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $used->id]);

        $this->delete("/admin/categories/{$empty->id}")->assertRedirect('/admin/categories')->assertSessionHas('success');
        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
    }
}
