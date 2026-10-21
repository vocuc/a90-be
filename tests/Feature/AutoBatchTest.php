<?php

namespace Tests\Feature;

use App\Enums\CreativeStatus;
use App\Models\AiCreative;
use App\Models\CreativeGroup;
use App\Models\Generation;
use App\Models\Hashtag;
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

    /**
     * @param  string[]  $keys
     * @param  string[]  $hashtags  mặc định khớp hashtag mà group() gắn cho creative
     */
    private function product(User $user, array $keys = ['product_image'], ?string $name = null, array $hashtags = ['Giày']): Product
    {
        $id = $this->actingAs($user)->post('/api/v1/creator/products', [
            'name' => $name,
            'hashtags' => $hashtags,
            'images' => array_map(fn (string $key) => ['file' => UploadedFile::fake()->image("{$key}.png", 32, 32), 'field_key' => $key], $keys),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return Product::query()->findOrFail($id);
    }

    /** @param  string[]  $names */
    private function tag(AiCreative $creative, array $names): AiCreative
    {
        $creative->hashtags()->sync(Hashtag::syncPivot($names));

        return $creative;
    }

    /** @param  AiCreative[]  $creatives  creative chưa có hashtag được gắn #giay (khớp product() mặc định) */
    private function group(User $user, array $creatives, string $name = 'Nhóm'): CreativeGroup
    {
        foreach ($creatives as $creative) {
            if (! $creative->hashtags()->exists()) {
                $this->tag($creative, ['giay']);
            }
        }
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
            ->assertJsonPath('data.hashtags.0.slug', 'giay')
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
            ->assertJsonPath('data.images.1.field_key', 'product_image')
            // Không gửi hashtags -> bỏ hết
            ->assertJsonCount(0, 'data.hashtags');

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

    public function test_product_hashtags_dedupe_and_share_creative_hashtags(): void
    {
        $user = $this->creator();
        $creative = $this->tag($this->publishedCreative($user), ['Giày']);

        $this->actingAs($user)->post('/api/v1/creator/products', [
            'hashtags' => ['#giay', 'GIÀY', 'Sale_50'],
            'images' => [['file' => UploadedFile::fake()->image('a.png'), 'field_key' => 'product_image']],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'data.hashtags')
            // Hashtag đã có (của creative) giữ tên cũ
            ->assertJsonPath('data.hashtags.0.name', 'Giày')
            ->assertJsonPath('data.hashtags.1.slug', 'sale_50');
        $this->assertSame(2, Hashtag::query()->count());
        $this->assertSame($creative->hashtags()->value('hashtags.id'), Product::query()->first()->hashtags()->value('hashtags.id'));

        $this->actingAs($user)->post('/api/v1/creator/products', [
            'hashtags' => ['a b', '#'],
            'images' => [['file' => UploadedFile::fake()->image('a.png'), 'field_key' => 'product_image']],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hashtags.0', 'hashtags.1']);
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

    public function test_order_runs_each_product_then_wraps_with_unused_group_first_and_new_product_first(): void
    {
        $user = $this->creator(credit: 100000);
        $g1 = $this->group($user, [$this->publishedCreative($user)], 'G1');
        $g2 = $this->group($user, [$this->publishedCreative($user)], 'G2');
        [$p1, $p2, $p3] = [$this->product($user), $this->product($user), $this->product($user)];

        $preview = $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?count=7')
            ->json('data.batches');

        $this->auto($user, 7)
            ->assertCreated()
            ->assertJsonCount(7, 'data.batches')
            ->assertJsonPath('data.stopped', null)
            ->assertJsonPath('data.batches.0.source', 'auto');

        $pairs = $this->pairs($user);
        // Xem trước chọn đúng các cặp sẽ tạo
        $this->assertSame(array_map(fn ($b) => [$b['product']['id'], $b['group']['id']], $preview), $pairs);
        // Mỗi vòng đủ các sản phẩm theo thứ tự tạo; vòng 2 mỗi sản phẩm sang nhóm chưa dùng
        $this->assertSame([$p1->id, $p2->id, $p3->id, $p1->id, $p2->id, $p3->id, $p1->id], array_column($pairs, 0));
        foreach ([0, 1, 2] as $i) {
            $this->assertEqualsCanonicalizing([$g1->id, $g2->id], [$pairs[$i][1], $pairs[$i + 3][1]]);
        }

        // Sản phẩm mới (0 lượt) chạy liền các nhóm tới khi bằng lượt sản phẩm khác, rồi tới P2 (2 lượt, ít hơn P1)
        $p4 = $this->product($user);
        $this->auto($user, 3)->assertCreated();
        $next = array_slice($this->pairs($user), 7);
        $this->assertSame([$p4->id, $p4->id, $p2->id], array_column($next, 0));
        $this->assertEqualsCanonicalizing([$g1->id, $g2->id], array_column(array_slice($next, 0, 2), 1));
        $this->assertSame(3, $p1->fresh()->auto_batch_count);
    }

    public function test_tied_groups_are_picked_randomly(): void
    {
        $user = $this->creator(credit: 1000000);
        foreach (range(1, 4) as $i) {
            $this->group($user, [$this->publishedCreative($user)], "G{$i}");
        }
        foreach (range(1, 20) as $i) {
            $this->product($user);
        }

        $this->auto($user, 20)->assertCreated()->assertJsonCount(20, 'data.batches');

        // 20 sản phẩm đều 0 lượt với mọi nhóm: không phải lúc nào cũng nhóm tạo trước
        $this->assertGreaterThan(1, count(array_unique(array_column($this->pairs($user), 1))));
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

    public function test_product_only_pairs_with_groups_sharing_a_hashtag(): void
    {
        $user = $this->creator(credit: 100000);
        $shoe = $this->tag($this->publishedCreative($user), ['giay']);
        $bag = $this->tag($this->publishedCreative($user), ['tui']);
        $shirt = $this->tag($this->publishedCreative($user), ['ao']);
        $pausedShoe = $this->tag($this->publishedCreative($user), ['giay']);
        $pausedShoe->update(['status' => CreativeStatus::Paused]);

        // Khớp nhờ $shoe: tạo cả nhóm, kể cả $bag không trùng hashtag
        $g1 = $this->group($user, [$shoe, $bag], 'G1');
        $g2 = $this->group($user, [$shirt], 'G2');
        // Creative trùng hashtag đang tạm dừng: nhóm không khớp
        $this->group($user, [$pausedShoe, $this->tag($this->publishedCreative($user), ['tui'])], 'G3');

        $shoes = $this->product($user, hashtags: ['#Giày', 'sale']);
        $shirts = $this->product($user, hashtags: ['ao']);
        // Không có hashtag: bỏ qua
        $plain = $this->product($user, hashtags: []);

        $this->auto($user, 3)->assertCreated()->assertJsonCount(3, 'data.batches');

        $this->assertSame([[$shoes->id, $g1->id], [$shirts->id, $g2->id], [$shoes->id, $g1->id]], $this->pairs($user));
        $this->assertSame([$shoe->id, $bag->id], Generation::query()->orderBy('id')->limit(2)->pluck('creative_id')->all());
        $this->assertSame(0, $plain->fresh()->auto_batch_count);
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
