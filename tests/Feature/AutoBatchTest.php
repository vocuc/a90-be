<?php

namespace Tests\Feature;

use App\Enums\CreativeStatus;
use App\Models\AiCreative;
use App\Models\CreativeGroup;
use App\Models\Generation;
use App\Models\ImageBatch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Tạo lô tự động, xem note/auto-tao-lo.txt ở repo frontend. */
class AutoBatchTest extends TestCase
{
    // ---- Helpers ----

    /** @param  string[]  $keys */
    private function product(User $user, array $keys = ['product_image'], ?string $name = null): Product
    {
        $id = $this->actingAs($user)->post('/api/v1/creator/products', [
            'name' => $name,
            'images' => array_map(fn (string $key) => ['file' => UploadedFile::fake()->image("{$key}.png", 32, 32), 'field_key' => $key], $keys),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return Product::query()->findOrFail($id);
    }

    /** @param  AiCreative[]  $creatives */
    private function group(User $user, array $creatives, string $name = 'Nhóm'): CreativeGroup
    {
        $id = $this->actingAs($user)->postJson('/api/v1/creator/creative-groups', [
            'name' => $name,
            'creative_ids' => array_map(fn (AiCreative $c) => $c->id, $creatives),
        ])->assertCreated()->json('data.id');

        return CreativeGroup::query()->findOrFail($id);
    }

    private function auto(User $user, int $count, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/image-batches/auto', array_merge([
            'count' => $count,
            'idempotency_key' => (string) Str::uuid(),
        ], $extra));
    }

    /** Cặp [product_id, group_id] của các lô tự động theo thứ tự tạo. */
    private function pairs(User $user): array
    {
        return ImageBatch::query()->where('user_id', $user->id)->orderBy('id')->get()
            ->map(fn (ImageBatch $b) => [$b->product_id, $b->creative_group_id])->all();
    }

    // ---- Sản phẩm ----

    public function test_product_crud_keeps_and_drops_images(): void
    {
        $user = $this->creator();
        $product = $this->product($user, ['product_image', 'back_image'], 'Áo thun');
        $images = $product->images()->get();

        $this->actingAs($user)->getJson("/api/v1/creator/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Áo thun')
            ->assertJsonPath('data.images.1.field_key', 'back_image')
            ->assertJsonPath('data.auto_batch_count', 0);

        // Giữ ảnh đầu (đổi key), bỏ ảnh sau, thêm ảnh mới
        $this->actingAs($user)->post("/api/v1/creator/products/{$product->id}", [
            'images' => [
                ['id' => $images[0]->id, 'field_key' => 'main'],
                ['file' => UploadedFile::fake()->image('new.png'), 'field_key' => 'product_image'],
            ],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', null)
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.images.0.field_key', 'main')
            ->assertJsonPath('data.images.1.field_key', 'product_image');

        $this->assertSame($images[0]->path, $product->images()->get()[0]->path);

        $this->actingAs($this->creator())->getJson("/api/v1/creator/products/{$product->id}")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/v1/creator/products/{$product->id}")->assertNoContent();
        $this->assertSame(0, Product::query()->count());
    }

    public function test_product_validates_field_keys(): void
    {
        $user = $this->creator();

        $this->actingAs($user)->post('/api/v1/creator/products', [
            'images' => [
                ['file' => UploadedFile::fake()->image('a.png'), 'field_key' => 'product_image'],
                ['file' => UploadedFile::fake()->image('b.png'), 'field_key' => 'product_image'],
                ['file' => UploadedFile::fake()->image('c.png'), 'field_key' => 'ảnh'],
            ],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.1.field_key', 'images.2.field_key']);

        // Không phải Creator -> middleware creator chặn
        $this->actingAs($this->buyer())->getJson('/api/v1/creator/products')->assertForbidden();
    }

    // ---- Nhóm ----

    public function test_group_only_own_creatives_and_one_group_per_creative(): void
    {
        $user = $this->creator();
        $a = $this->publishedCreative($user);
        $b = $this->publishedCreative($user);
        $other = $this->publishedCreative($this->creator());

        $first = $this->group($user, [$a], 'Nhóm 1');

        $this->actingAs($user)->postJson('/api/v1/creator/creative-groups', ['name' => 'Nhóm 2', 'creative_ids' => [$b->id, $a->id, $other->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['creative_ids.1', 'creative_ids.2'])
            ->assertJsonMissingValidationErrors('creative_ids.0');

        // Sửa chính nhóm đang chứa creative thì được
        $this->actingAs($user)->putJson("/api/v1/creator/creative-groups/{$first->id}", ['name' => 'Nhóm 1', 'creative_ids' => [$b->id, $a->id]])
            ->assertOk()
            ->assertJsonPath('data.creatives.0.id', $b->id)
            ->assertJsonPath('data.creatives.1.id', $a->id);

        $this->actingAs($user)->getJson('/api/v1/creator/creatives')
            ->assertOk()
            ->assertJsonPath('data.0.group.name', 'Nhóm 1');
    }

    // ---- Tạo tự động ----

    public function test_order_runs_all_products_per_group_then_wraps_and_new_product_first(): void
    {
        $user = $this->creator(credit: 100000);
        $g1 = $this->group($user, [$this->publishedCreative($user)], 'G1');
        $g2 = $this->group($user, [$this->publishedCreative($user)], 'G2');
        [$p1, $p2, $p3] = [$this->product($user), $this->product($user), $this->product($user)];

        $this->auto($user, 7)
            ->assertCreated()
            ->assertJsonCount(7, 'data.batches')
            ->assertJsonPath('data.stopped', null)
            ->assertJsonPath('data.batches.0.source', 'auto')
            ->assertJsonPath('data.batches.0.group.name', 'G1');

        $this->assertSame([
            [$p1->id, $g1->id], [$p2->id, $g1->id], [$p3->id, $g1->id],
            [$p1->id, $g2->id], [$p2->id, $g2->id], [$p3->id, $g2->id],
            [$p1->id, $g1->id],
        ], $this->pairs($user));

        // Sản phẩm mới (0 lượt) chạy liền các nhóm tới khi bằng lượt sản phẩm khác, rồi tới P2 (2 lượt, ít hơn P1)
        $p4 = $this->product($user);
        $this->auto($user, 3)->assertCreated();
        $this->assertSame([[$p4->id, $g1->id], [$p4->id, $g2->id], [$p2->id, $g1->id]], array_slice($this->pairs($user), 7));
        $this->assertSame(3, $p1->fresh()->auto_batch_count);
    }

    public function test_product_image_fills_linked_field_and_invalid_creatives_are_skipped(): void
    {
        $user = $this->creator(credit: 100000);
        $ok = $this->publishedCreative($user, price: 1000);
        // Không có trường product_image
        $noField = $this->publishedCreative($user, config: ['input_schema' => [
            ['key' => 'model_photo', 'label' => 'Ảnh mẫu', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []],
        ]]);
        // Còn trường chữ bắt buộc không có mặc định
        $needsText = $this->publishedCreative($user, config: ['input_schema' => [
            ['key' => 'product_image', 'label' => 'Ảnh', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []],
            ['key' => 'slogan', 'label' => 'Slogan', 'type' => 'text', 'required' => true, 'sort_order' => 2, 'validation' => []],
        ]]);
        // Trường chữ bắt buộc có mặc định thì dùng mặc định
        $withDefault = $this->publishedCreative($user, price: 2000, config: ['input_schema' => [
            ['key' => 'product_image', 'label' => 'Ảnh', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []],
            ['key' => 'slogan', 'label' => 'Slogan', 'type' => 'text', 'required' => true, 'sort_order' => 2, 'validation' => [], 'default_value' => 'Sale'],
        ]]);
        $paused = $this->publishedCreative($user);
        $paused->update(['status' => CreativeStatus::Paused]);

        $this->group($user, [$ok, $noField, $needsText, $withDefault, $paused]);
        $product = $this->product($user);

        $this->auto($user, 1)
            ->assertCreated()
            ->assertJsonPath('data.batches.0.image_count', 2);

        $this->assertSame(97000, $this->balance($user));
        $path = $product->images()->first()->path;
        $generations = Generation::query()->orderBy('id')->get();
        $this->assertSame([$ok->id, $withDefault->id], $generations->pluck('creative_id')->all());
        $this->assertSame(['product_image' => $path], $generations[0]->input_paths);
        $this->assertSame('Sale', $generations[1]->input_values['slogan']);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_pair_without_valid_creative_is_skipped_and_not_counted(): void
    {
        $user = $this->creator(credit: 100000);
        $g1 = $this->group($user, [$this->publishedCreative($user)]);
        $p1 = $this->product($user, ['other_key']);
        $p2 = $this->product($user);

        $this->auto($user, 2)->assertCreated()->assertJsonCount(2, 'data.batches');
        $this->assertSame([[$p2->id, $g1->id], [$p2->id, $g1->id]], $this->pairs($user));
        $this->assertSame(0, $p1->fresh()->auto_batch_count);

        $this->actingAs($user)->deleteJson("/api/v1/creator/products/{$p2->id}")->assertNoContent();
        $this->auto($user, 1)->assertStatus(422)->assertJsonPath('code', 'AUTO_NOTHING_TO_CREATE');
        $this->actingAs($this->creator())->postJson('/api/v1/image-batches/auto', ['count' => 1, 'idempotency_key' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'AUTO_NOTHING_TO_CREATE');
    }

    public function test_stops_when_credit_runs_out(): void
    {
        $user = $this->creator(credit: 2500);
        $this->group($user, [$this->publishedCreative($user, price: 1000)]);
        $this->product($user);

        $this->auto($user, 5)
            ->assertCreated()
            ->assertJsonCount(2, 'data.batches')
            ->assertJsonPath('data.requested', 5)
            ->assertJsonPath('data.stopped', 'insufficient_credit')
            ->assertJsonPath('data.required', 1000);
        $this->assertSame(500, $this->balance($user));

        $this->auto($user, 1)->assertStatus(402)->assertJsonPath('code', 'INSUFFICIENT_CREDIT');
        $this->assertSame(2, ImageBatch::query()->count());
    }

    public function test_same_idempotency_key_returns_same_batches(): void
    {
        $user = $this->creator(credit: 10000);
        $this->group($user, [$this->publishedCreative($user, price: 1000)]);
        $product = $this->product($user);

        $first = $this->auto($user, 2, ['idempotency_key' => 'same'])->json('data.batches.*.id');
        $second = $this->auto($user, 2, ['idempotency_key' => 'same'])->json('data.batches.*.id');

        $this->assertSame($first, $second);
        $this->assertSame(8000, $this->balance($user));
        $this->assertSame(2, $product->fresh()->auto_batch_count);
    }

    public function test_preview_creates_nothing(): void
    {
        $user = $this->creator(credit: 1500);
        $creative = $this->publishedCreative($user, price: 1000);
        $this->group($user, [$creative], 'G1');
        $this->product($user, name: 'Áo');

        $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?count=3')
            ->assertOk()
            ->assertJsonCount(3, 'data.batches')
            ->assertJsonPath('data.batches.0.product.name', 'Áo')
            ->assertJsonPath('data.batches.0.group.name', 'G1')
            ->assertJsonPath('data.batches.0.creatives.0.id', $creative->id)
            ->assertJsonPath('data.batches.0.cost', 1000)
            ->assertJsonPath('data.total_cost', 3000)
            ->assertJsonPath('data.balance', 1500);

        $this->assertSame(0, ImageBatch::query()->count());
    }
}
