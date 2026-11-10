<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Chia sẻ sản phẩm cho tài khoản A51 khác theo email: người được chia sẻ dùng để tạo ảnh, không sửa được. */
class ProductShareTest extends TestCase
{
    private function product(User $owner): Product
    {
        $id = $this->actingAs($owner)->post('/api/v1/creator/products', [
            'name' => 'Giày batman',
            'images' => [['file' => UploadedFile::fake()->image('giay.png', 32, 32), 'field_key' => 'anh_san_pham']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return Product::query()->findOrFail($id);
    }

    public function test_owner_shares_by_email_and_recipient_can_use_but_not_edit(): void
    {
        $owner = $this->creator();
        $friend = $this->creator(credit: 5000);
        $product = $this->product($owner);

        // Chưa chia sẻ: người khác không thấy, không dùng được
        $this->actingAs($friend)->getJson("/api/v1/creator/products/{$product->id}")->assertNotFound();

        $this->actingAs($owner)->putJson("/api/v1/creator/products/{$product->id}/shares", ['emails' => [strtoupper($friend->email)]])
            ->assertOk()->assertJsonPath('data.0.email', $friend->email);
        $this->actingAs($owner)->getJson("/api/v1/creator/products/{$product->id}/shares")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson('/api/v1/creator/products')
            ->assertJsonPath('data.0.is_owner', true)->assertJsonPath('data.0.share_count', 1);

        // Người được chia sẻ thấy sản phẩm trong danh sách, biết ai chia sẻ
        $this->actingAs($friend)->getJson('/api/v1/creator/products')->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.is_owner', false)
            ->assertJsonPath('data.0.owner.id', $owner->id)
            ->assertJsonPath('data.0.share_count', null);
        $imageId = $product->images()->value('id');
        $this->actingAs($friend)->get("/api/v1/creator/products/{$product->id}/images/{$imageId}/file")->assertOk();

        // Dùng sản phẩm để tạo ảnh
        $creative = $this->publishedCreative($this->creator());
        $generationId = $this->generate($friend, $creative, extra: ['product_id' => $product->id])->assertStatus(202)->json('data.id');
        $this->assertSame($product->id, Generation::query()->findOrFail($generationId)->product_id);

        // Không sửa / xoá / bật tắt / chia sẻ tiếp được
        $this->actingAs($friend)->post("/api/v1/creator/products/{$product->id}", [
            'images' => [['id' => $imageId, 'field_key' => 'anh_san_pham']],
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($friend)->deleteJson("/api/v1/creator/products/{$product->id}")->assertNotFound();
        $this->actingAs($friend)->putJson("/api/v1/creator/products/{$product->id}/active", ['is_active' => false])->assertNotFound();
        $this->actingAs($friend)->putJson("/api/v1/creator/products/{$product->id}/shares", ['emails' => []])->assertNotFound();
        $this->actingAs($friend)->getJson("/api/v1/creator/products/{$product->id}/shares")->assertNotFound();

        // Bỏ chia sẻ: hết thấy, hết dùng
        $this->actingAs($owner)->putJson("/api/v1/creator/products/{$product->id}/shares", ['emails' => []])->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($friend)->getJson('/api/v1/creator/products')->assertOk()->assertJsonCount(0, 'data');
        $this->generate($friend, $creative, extra: ['product_id' => $product->id, 'idempotency_key' => 'k-2'])
            ->assertStatus(422)->assertJsonPath('errors.product_id.0', 'Sản phẩm không tồn tại.');
    }

    public function test_rejects_unknown_email_and_self(): void
    {
        $owner = $this->creator();
        $product = $this->product($owner);

        $this->actingAs($owner)->putJson("/api/v1/creator/products/{$product->id}/shares", ['emails' => ['khong-co@example.com', $owner->email, 'sai-email']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['emails.2' => 'Email không hợp lệ.']);

        $this->actingAs($owner)->putJson("/api/v1/creator/products/{$product->id}/shares", ['emails' => ['khong-co@example.com', $owner->email]])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'emails.0' => 'Không tìm thấy tài khoản A51 có email khong-co@example.com.',
                'emails.1' => 'Không thể chia sẻ cho chính mình.',
            ]);
        $this->assertSame(0, $product->sharedWith()->count());
    }

    public function test_generated_image_count_is_per_user(): void
    {
        $owner = $this->creator(credit: 5000);
        $friend = $this->creator(credit: 5000);
        $product = $this->product($owner);
        $product->sharedWith()->attach($friend->id);
        $creative = $this->publishedCreative($this->creator());

        $this->generate($friend, $creative, quantity: 2, extra: ['product_id' => $product->id])->assertStatus(202);
        $this->runWorker([null, null]);

        $this->actingAs($friend)->getJson("/api/v1/creator/products/{$product->id}")->assertJsonPath('data.generated_image_count', 2);
        $this->actingAs($owner)->getJson("/api/v1/creator/products/{$product->id}")->assertJsonPath('data.generated_image_count', 0);
    }

    public function test_bulk_share_selected_or_all_and_unshare(): void
    {
        $owner = $this->creator();
        [$a, $b] = [$this->creator(), $this->creator()];
        $p1 = $this->product($owner);
        $p2 = $this->product($owner);
        $p3 = $this->product($owner);
        $foreign = $this->product($this->creator());
        $p1->sharedWith()->attach($b->id); // đang chia sẻ sẵn, chia sẻ hàng loạt không được xoá

        // Chọn sản phẩm
        $this->actingAs($owner)->postJson('/api/v1/creator/products/shares', [
            'action' => 'share', 'emails' => [$a->email], 'product_ids' => [$p1->id, $p2->id],
        ])->assertOk()->assertJsonPath('data.product_count', 2);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $p1->sharedWith()->pluck('users.id')->all());
        $this->assertSame([$a->id], $p2->sharedWith()->pluck('users.id')->all());
        $this->assertSame(0, $p3->sharedWith()->count());

        // Sản phẩm của người khác / chưa chọn gì -> lỗi, không đổi gì
        $this->actingAs($owner)->postJson('/api/v1/creator/products/shares', [
            'action' => 'share', 'emails' => [$a->email], 'product_ids' => [$p3->id, $foreign->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['product_ids' => 'Có sản phẩm không tồn tại.']);
        $this->actingAs($owner)->postJson('/api/v1/creator/products/shares', [
            'action' => 'share', 'emails' => [$a->email], 'product_ids' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['product_ids']);
        $this->assertSame(0, $p3->sharedWith()->count());
        $this->assertSame(0, $foreign->sharedWith()->count());

        // Tất cả sản phẩm của mình
        $this->actingAs($owner)->postJson('/api/v1/creator/products/shares', [
            'action' => 'share', 'emails' => [$a->email, $b->email], 'all' => true,
        ])->assertOk()->assertJsonPath('data.product_count', 3);
        $this->actingAs($a)->getJson('/api/v1/creator/products')->assertJsonCount(3, 'data');
        $this->assertSame(0, $foreign->sharedWith()->count());

        // Bỏ chia sẻ 1 người ở mọi sản phẩm, người còn lại giữ nguyên
        $this->actingAs($owner)->postJson('/api/v1/creator/products/shares', [
            'action' => 'unshare', 'emails' => [$a->email], 'all' => true,
        ])->assertOk();
        $this->actingAs($a)->getJson('/api/v1/creator/products')->assertJsonCount(0, 'data');
        $this->actingAs($b)->getJson('/api/v1/creator/products')->assertJsonCount(3, 'data');

        // Email sai -> lỗi theo dòng
        $this->actingAs($owner)->postJson('/api/v1/creator/products/shares', [
            'action' => 'share', 'emails' => ['khong-co@example.com'], 'all' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['emails.0' => 'Không tìm thấy tài khoản A51 có email khong-co@example.com.']);
    }
}
