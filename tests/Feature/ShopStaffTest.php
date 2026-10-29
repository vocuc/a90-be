<?php

namespace Tests\Feature;

use App\Models\AiCreative;
use App\Models\CreativeGroup;
use App\Models\Hashtag;
use App\Models\ImageBatch;
use App\Models\Product;
use App\Models\ShopStaff;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Nhân viên của shop, xem note/chia-se-tai-nguyen.txt ở repo frontend. */
class ShopStaffTest extends TestCase
{
    // ---- Helpers ----

    private function addStaff(User $owner, User $user): TestResponse
    {
        return $this->actingAs($owner)->postJson('/api/v1/creator/staff', ['email' => $user->email]);
    }

    /** Chủ shop + nhân viên (có tiền) + sản phẩm, nhóm 1 creative riêng tư của shop (#giay). */
    private function shop(): array
    {
        $owner = $this->creator();
        $staff = $this->buyer(100000);
        $this->addStaff($owner, $staff)->assertCreated();

        $creative = $this->publishedCreative($owner, 1000);
        $creative->update(['is_public' => false]);
        $creative->hashtags()->sync(Hashtag::syncPivot(['giay']));

        $productId = $this->actingAs($owner)->post('/api/v1/creator/products', [
            'hashtags' => ['giay'],
            'images' => [['file' => UploadedFile::fake()->image('p.png', 32, 32), 'field_key' => 'product_image']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $groupId = $this->actingAs($owner)->postJson('/api/v1/creator/creative-groups', [
            'name' => 'Nhóm shop',
            'creative_ids' => [$creative->id],
        ])->assertCreated()->json('data.id');

        return [$owner, $staff, $creative, Product::query()->findOrFail($productId), CreativeGroup::query()->findOrFail($groupId)];
    }

    private function auto(User $user, int $count, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/image-batches/auto', array_merge([
            'count' => $count,
            'idempotency_key' => (string) Str::uuid(),
        ], $extra));
    }

    private function manual(User $user, AiCreative $creative): TestResponse
    {
        return $this->actingAs($user)->post('/api/v1/image-batches', [
            'items' => [[
                'creative_id' => $creative->id,
                'quantity' => 1,
                'inputs' => ['product_image' => UploadedFile::fake()->image('p.png', 32, 32), 'aspectRatio' => '1:1'],
            ]],
            'idempotency_key' => (string) Str::uuid(),
        ], ['Accept' => 'application/json']);
    }

    // ---- Thêm / gỡ nhân viên ----

    public function test_owner_adds_staff_by_email_with_rules(): void
    {
        $owner = $this->creator();
        $staff = $this->buyer();

        $this->addStaff($owner, $staff)->assertCreated()->assertJsonPath('data.user.email', $staff->email);
        $this->actingAs($owner)->getJson('/api/v1/creator/staff')->assertOk()->assertJsonPath('data.0.user.id', $staff->id);
        $this->actingAs($staff)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.staff_of.shop_name', $owner->creatorProfile->shop_name);

        $error = fn (TestResponse $r) => $r->assertStatus(422)->json('errors.email.0');
        $this->assertSame('Tài khoản đã là nhân viên của shop bạn.', $error($this->addStaff($owner, $staff)));
        $this->assertSame('Tài khoản đã thuộc shop khác.', $error($this->addStaff($this->creator(), $staff)));
        $this->assertSame('Tài khoản đã có shop.', $error($this->addStaff($owner, $this->creator())));
        $this->assertSame('Không thể thêm chính bạn.', $error($this->addStaff($owner, $owner)));
        $this->assertSame('Không tìm thấy tài khoản với email này.', $error(
            $this->actingAs($owner)->postJson('/api/v1/creator/staff', ['email' => 'khong-co@example.com'])
        ));

        // Không có shop thì không quản lý nhân viên được
        $this->actingAs($this->buyer())->getJson('/api/v1/creator/staff')->assertForbidden();
    }

    public function test_staff_cannot_open_shop_until_leaving(): void
    {
        $owner = $this->creator();
        $staff = $this->buyer();
        $this->addStaff($owner, $staff)->assertCreated();
        $profile = ['shop_name' => 'Shop riêng', 'username' => 'shop-rieng'];

        $this->actingAs($staff)->postJson('/api/v1/creator/profile', $profile)->assertStatus(409);

        $this->actingAs($staff)->deleteJson('/api/v1/staff/shop')->assertNoContent();
        $this->actingAs($staff->fresh())->postJson('/api/v1/creator/profile', $profile)->assertCreated();
        $this->actingAs($staff->fresh())->deleteJson('/api/v1/staff/shop')->assertForbidden()->assertJsonPath('code', 'NOT_STAFF');
    }

    public function test_owner_removes_staff(): void
    {
        $owner = $this->creator();
        $staff = $this->buyer();
        $id = $this->addStaff($owner, $staff)->json('data.id');

        $this->actingAs($this->creator())->deleteJson("/api/v1/creator/staff/{$id}")->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/v1/creator/staff/{$id}")->assertNoContent();
        $this->assertSame(0, ShopStaff::query()->count());
    }

    // ---- Dùng tài nguyên shop ----

    public function test_staff_sees_shop_products_and_groups_read_only(): void
    {
        [$owner, $staff, , $product, $group] = $this->shop();

        $this->actingAs($staff)->getJson('/api/v1/creator/products')->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.is_owner', false);
        $this->actingAs($staff)->getJson("/api/v1/creator/products/{$product->id}")->assertOk();
        $this->actingAs($staff)->getJson('/api/v1/creator/creative-groups')->assertOk()
            ->assertJsonPath('data.0.id', $group->id)
            ->assertJsonPath('data.0.is_owner', false);
        $this->actingAs($owner)->getJson('/api/v1/creator/products')->assertJsonPath('data.0.is_owner', true);

        // Không sửa / xoá được
        $this->actingAs($staff)->deleteJson("/api/v1/creator/products/{$product->id}")->assertForbidden();
        $this->actingAs($staff)->deleteJson("/api/v1/creator/creative-groups/{$group->id}")->assertForbidden();

        // Người ngoài không xem được
        $this->actingAs($this->buyer())->getJson('/api/v1/creator/products')->assertForbidden();
    }

    public function test_staff_auto_batch_uses_shop_resources_charges_staff_and_counts_separately(): void
    {
        [$owner, $staff, , $product, $group] = $this->shop();
        $owner->creditWallet()->update(['balance' => 100000]);

        $this->auto($staff, 2)->assertCreated()->assertJsonCount(1, 'data.batches');
        $batch = ImageBatch::query()->where('user_id', $staff->id)->firstOrFail();
        $this->assertSame([$product->id, $group->id, $owner->id], [$batch->product_id, $batch->creative_group_id, $batch->shop_owner_id]);
        // Trừ ví nhân viên theo giá bán
        $this->assertSame(100000 - 1000, $this->balance($staff));
        $this->assertSame(100000, $this->balance($owner));

        // Lượt đếm riêng: chủ shop chưa chạy lần nào
        $this->actingAs($staff)->getJson("/api/v1/creator/products/{$product->id}")->assertJsonPath('data.auto_batch_count', 1);
        $this->actingAs($owner)->getJson("/api/v1/creator/products/{$product->id}")->assertJsonPath('data.auto_batch_count', 0);
        $this->auto($owner, 1)->assertCreated();
        $this->assertNull(ImageBatch::query()->where('user_id', $owner->id)->value('shop_owner_id'));
    }

    public function test_staff_manual_batch_with_private_shop_creative(): void
    {
        [$owner, $staff, $creative] = $this->shop();

        $this->actingAs($staff)->getJson('/api/v1/staff/creatives')->assertOk()->assertJsonPath('data.0.id', $creative->id);
        $this->actingAs($staff)->getJson("/api/v1/creatives/{$creative->slug}")->assertOk();

        $id = $this->manual($staff, $creative)->assertCreated()->json('data.id');
        $this->assertSame($owner->id, ImageBatch::query()->findOrFail($id)->shop_owner_id);

        // Người ngoài không dùng được creative riêng tư
        $this->manual($this->buyer(100000), $creative)->assertNotFound();
    }

    public function test_owner_views_staff_batches_even_after_removal_and_removed_staff_cannot_regenerate(): void
    {
        [$owner, $staff, $creative] = $this->shop();
        $id = $this->manual($staff, $creative)->assertCreated()->json('data.id');
        $imageId = ImageBatch::query()->findOrFail($id)->images()->value('id');
        $this->manual($staff, $this->publishedCreative($this->creator()))->assertCreated();

        $list = fn () => $this->actingAs($owner)->getJson("/api/v1/creator/staff-batches?user_id={$staff->id}")->assertOk();
        // Chỉ lô có dùng tài nguyên shop
        $list()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $staff->id);
        $this->actingAs($owner)->getJson("/api/v1/creator/staff-batches/{$id}")->assertOk()->assertJsonCount(1, 'data.images');
        $this->actingAs($this->creator())->getJson("/api/v1/creator/staff-batches/{$id}")->assertNotFound();

        $this->actingAs($staff)->deleteJson('/api/v1/staff/shop')->assertNoContent();
        $list()->assertJsonCount(1, 'data');
        $this->actingAs($staff->fresh())->postJson("/api/v1/image-batches/{$id}/images/{$imageId}/regenerate", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden()->assertJsonPath('code', 'SHOP_ACCESS_REVOKED');
        // Mất quyền dùng sản phẩm của shop
        $this->actingAs($staff->fresh())->getJson('/api/v1/creator/products')->assertForbidden();
    }

    public function test_staff_logo_is_used_for_insert_logo(): void
    {
        [, $staff] = $this->shop();

        $this->auto($staff, 1, ['insert_logo' => true])->assertStatus(422)
            ->assertJsonPath('errors.insert_logo.0', 'Hãy tải logo lên để dùng tính năng này.');

        $url = $this->actingAs($staff)->post('/api/v1/staff/logo', ['image' => UploadedFile::fake()->image('logo.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.logo_url');
        $this->assertNotNull($url);
        $this->actingAs($staff->fresh())->getJson('/api/v1/auth/me')->assertJsonPath('data.logo_url', $url);

        $this->auto($staff->fresh(), 1, ['insert_logo' => true])->assertCreated();
        $this->assertTrue((bool) ImageBatch::query()->where('user_id', $staff->id)->firstOrFail()->images()->first()->generation->insert_logo);

        // Không phải nhân viên thì không tải logo kiểu này
        $this->actingAs($this->buyer())->post('/api/v1/staff/logo', ['image' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
