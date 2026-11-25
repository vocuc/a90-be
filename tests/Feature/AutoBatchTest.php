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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    private function product(User $user, array $keys = ['anh_san_pham'], ?string $name = null, array $hashtags = ['Giày']): Product
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

    /** Số lần chạy tự động của chủ sản phẩm với sản phẩm (mỗi người đếm riêng). */
    private function autoCount(Product $product): int
    {
        return (int) DB::table('auto_batch_product_counts')
            ->where('user_id', $product->user_id)
            ->where('product_id', $product->id)
            ->value('count');
    }

    /** Cặp [product_id, group_id] của các lô tự động theo thứ tự tạo. */
    private function pairs(User $user): array
    {
        return ImageBatch::query()->withProductId()->where('user_id', $user->id)->orderBy('id')->get()
            ->map(fn (ImageBatch $b) => [$b->product_id, $b->creative_group_id])->all();
    }

    // ---- Sản phẩm ----

    public function test_product_crud_keeps_and_drops_images(): void
    {
        $user = $this->creator();
        $product = $this->product($user, ['anh_san_pham', 'back_image'], 'Áo thun');
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
                ['file' => UploadedFile::fake()->image('new.png'), 'field_key' => 'anh_san_pham'],
            ],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', null)
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.images.0.field_key', 'main')
            ->assertJsonPath('data.images.1.field_key', 'anh_san_pham')
            // Không gửi hashtags -> bỏ hết
            ->assertJsonCount(0, 'data.hashtags');

        $this->assertSame($images[0]->path, $product->images()->get()[0]->path);

        $this->actingAs($this->creator())->getJson("/api/v1/creator/products/{$product->id}")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/v1/creator/products/{$product->id}")->assertNoContent();
        $this->assertSame(0, Product::query()->count());
    }

    public function test_product_image_gets_thumb_400_wide_and_original_is_kept(): void
    {
        $user = $this->creator();

        $response = $this->actingAs($user)->post('/api/v1/creator/products', [
            'images' => [['file' => UploadedFile::fake()->image('tall.png', 1000, 1500), 'field_key' => 'anh_san_pham']],
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = Product::query()->sole()->images()->sole();
        $this->assertNotNull($image->thumb_path);
        $this->assertNotSame($image->path, $image->thumb_path);

        $disk = Storage::disk('media');
        $this->assertSame([1000, 1500], array_slice(getimagesizefromstring($disk->get($image->path)), 0, 2));
        $this->assertSame([400, 600], array_slice(getimagesizefromstring($disk->get($image->thumb_path)), 0, 2));

        $response->assertJsonPath('data.cover_url', "https://media.test/{$image->thumb_path}")
            ->assertJsonPath('data.images.0.url', "https://media.test/{$image->path}")
            ->assertJsonPath('data.images.0.thumb_url', "https://media.test/{$image->thumb_path}");
    }

    public function test_product_requires_main_image_frame(): void
    {
        $user = $this->creator();
        $json = ['Accept' => 'application/json'];

        // Thiếu khung "Ảnh sản phẩm" anh_san_pham -> 422 ở images
        $this->actingAs($user)->post('/api/v1/creator/products', [
            'images' => [['file' => UploadedFile::fake()->image('a.png'), 'field_key' => 'phu_kien']],
        ], $json)->assertStatus(422)->assertJsonValidationErrors('images');

        $product = $this->product($user, ['anh_san_pham', 'phu_kien']);
        [$main, $extra] = [$product->images()->where('field_key', 'anh_san_pham')->value('id'), $product->images()->where('field_key', 'phu_kien')->value('id')];

        // Sửa mà bỏ khung ảnh sản phẩm -> 422, ảnh giữ nguyên
        $this->actingAs($user)->post("/api/v1/creator/products/{$product->id}", [
            'images' => [['id' => $extra, 'field_key' => 'phu_kien']],
        ], $json)->assertStatus(422)->assertJsonValidationErrors('images');
        $this->assertSame(2, $product->images()->count());

        // Đổi ảnh trong khung (ảnh mới, cùng key) -> được
        $this->actingAs($user)->post("/api/v1/creator/products/{$product->id}", [
            'images' => [['file' => UploadedFile::fake()->image('moi.png'), 'field_key' => 'anh_san_pham'], ['id' => $extra, 'field_key' => 'phu_kien']],
        ], $json)->assertOk()->assertJsonPath('data.images.0.field_key', 'anh_san_pham');
        $this->assertNotSame($main, $product->images()->where('field_key', 'anh_san_pham')->value('id'));
    }

    public function test_migration_renames_product_image_to_anh_san_pham(): void
    {
        $user = $this->creator();
        $migration = require database_path('migrations/2026_11_25_100000_rename_product_image_field_to_anh_san_pham.php');

        // Sản phẩm cũ key product_image; sản phẩm có cả 2 key thì giữ nguyên (tránh trùng)
        $old = Product::query()->create(['user_id' => $user->id, 'name' => 'Cũ']);
        $old->images()->create(['field_key' => 'product_image', 'path' => 'products/a.png', 'position' => 0]);
        $old->images()->create(['field_key' => 'phu_kien', 'path' => 'products/b.png', 'position' => 1]);
        $both = Product::query()->create(['user_id' => $user->id, 'name' => 'Cả hai']);
        $both->images()->create(['field_key' => 'anh_san_pham', 'path' => 'products/c.png', 'position' => 0]);
        $both->images()->create(['field_key' => 'product_image', 'path' => 'products/d.png', 'position' => 1]);

        // Creative trường product_image (prompt giữ nguyên); Creative có cả 2 trường thì bỏ qua
        $field = fn (string $key) => ['key' => $key, 'label' => 'Ảnh', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []];
        $prompt = 'Đi đôi giày ở productImage {{product_image}}';
        $creative = $this->publishedCreative($user, ['input_schema' => [$field('product_image'), $field('outfit_image')], 'prompt_template' => $prompt]);
        $mixed = $this->publishedCreative($user, ['input_schema' => [$field('product_image'), $field('anh_san_pham')]]);

        // Bảng generations đã bỏ / thêm cột qua nhiều migration -> chỉ ghi cột còn tồn tại
        $generationId = DB::table('generations')->insertGetId(array_intersect_key([
            'user_id' => $user->id, 'creative_id' => $creative->id, 'creator_id' => $user->id, 'model_id' => $creative->model_id,
            'type' => 'owner', 'input_paths' => json_encode(['product_image' => 'in/a.png', 'outfit_image' => 'in/b.png']),
            'aspect_ratio' => '1:1', 'quantity' => 1, 'price_per_image' => 0, 'prompt_snapshot' => 'x', 'idempotency_key' => 'mig',
            'created_at' => now(), 'updated_at' => now(),
        ], array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('generations'))));

        $migration->up();

        $this->assertEqualsCanonicalizing(['anh_san_pham', 'phu_kien'], $old->images()->pluck('field_key')->all());
        $this->assertEqualsCanonicalizing(['anh_san_pham', 'product_image'], $both->images()->pluck('field_key')->all());
        $config = $creative->config()->first();
        $this->assertSame(['anh_san_pham', 'outfit_image'], array_column($config->input_schema, 'key'));
        $this->assertSame($prompt, $config->prompt_template);
        $this->assertSame(['product_image', 'anh_san_pham'], array_column($mixed->config()->first()->input_schema, 'key'));
        $this->assertSame(['anh_san_pham' => 'in/a.png', 'outfit_image' => 'in/b.png'],
            json_decode(DB::table('generations')->where('id', $generationId)->value('input_paths'), true));
    }

    public function test_product_validates_field_keys(): void
    {
        $user = $this->creator();

        $this->actingAs($user)->post('/api/v1/creator/products', [
            'images' => [
                ['file' => UploadedFile::fake()->image('a.png'), 'field_key' => 'anh_san_pham'],
                ['file' => UploadedFile::fake()->image('b.png'), 'field_key' => 'anh_san_pham'],
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
            'images' => [['file' => UploadedFile::fake()->image('a.png'), 'field_key' => 'anh_san_pham']],
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
            'images' => [['file' => UploadedFile::fake()->image('a.png'), 'field_key' => 'anh_san_pham']],
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

    public function test_group_channel_must_be_own_and_channel_delete_makes_group_shared(): void
    {
        $user = $this->creator();
        $channel = $this->channelFor($user);
        $otherChannel = $this->channelFor($this->creator());
        $creative = $this->publishedCreative($user);

        $this->actingAs($user)->postJson('/api/v1/creator/creative-groups', ['name' => 'G', 'channel_id' => $otherChannel->id, 'creative_ids' => [$creative->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel_id');

        $id = $this->actingAs($user)->postJson('/api/v1/creator/creative-groups', ['name' => 'G', 'channel_id' => $channel->id, 'creative_ids' => [$creative->id]])
            ->assertCreated()
            ->assertJsonPath('data.channel.id', $channel->id)
            ->assertJsonPath('data.channel.name', $channel->name)
            ->json('data.id');

        // Sửa không gửi channel_id -> thành dùng chung
        $this->actingAs($user)->putJson("/api/v1/creator/creative-groups/{$id}", ['name' => 'G', 'creative_ids' => [$creative->id]])
            ->assertOk()
            ->assertJsonPath('data.channel', null);

        CreativeGroup::query()->whereKey($id)->update(['channel_id' => $channel->id]);
        $channel->delete();
        $this->assertNull(CreativeGroup::query()->findOrFail($id)->channel_id);
    }

    public function test_channel_groups_only_used_for_their_channel(): void
    {
        $user = $this->creator();
        $a = $this->channelFor($user);
        $b = $this->channelFor($user);
        $shared = $this->group($user, [$this->publishedCreative($user)], 'Chung');
        $onlyA = $this->group($user, [$this->publishedCreative($user)], 'Riêng A');
        $onlyA->update(['channel_id' => $a->id]);
        $onlyB = $this->group($user, [$this->publishedCreative($user)], 'Riêng B');
        $onlyB->update(['channel_id' => $b->id]);
        foreach (range(1, 5) as $i) {
            $this->product($user);
        }

        $groupsFor = fn (?int $channelId) => collect(range(1, 10))->flatMap(fn (int $seed) => array_column(array_column(
            $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?count=5&seed='.$seed.($channelId ? "&channel_id={$channelId}" : ''))
                ->assertOk()->json('data.batches'), 'group'), 'id'))->unique()->sort()->values()->all();

        // Không theo kênh: chỉ nhóm dùng chung; theo kênh: dùng chung + riêng của kênh đó
        $this->assertSame([$shared->id], $groupsFor(null));
        $this->assertSame([$shared->id, $onlyA->id], $groupsFor($a->id));
        $this->assertSame([$shared->id, $onlyB->id], $groupsFor($b->id));

        $this->auto($user, 5, ['channel_id' => $a->id])->assertCreated()->assertJsonCount(2, 'data.batches');
        $this->assertEqualsCanonicalizing([$shared->id, $onlyA->id], array_column($this->pairs($user), 1));

        // Kênh của người khác -> 422
        $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?count=1&channel_id='.$this->channelFor($this->creator())->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel_id');

        // Lọc danh sách nhóm theo kênh
        $this->assertEqualsCanonicalizing([$shared->id, $onlyB->id],
            array_column($this->actingAs($user)->getJson("/api/v1/creator/creative-groups?channel_id={$b->id}")->assertOk()->json('data'), 'id'));
    }

    // ---- Tạo tự động ----

    public function test_picks_distinct_random_products_and_groups_like_preview(): void
    {
        $user = $this->creator();
        $groups = array_map(fn (int $i) => $this->group($user, [$this->publishedCreative($user)], "G{$i}")->id, range(1, 4));
        $products = array_map(fn () => $this->product($user)->id, range(1, 5));

        $preview = $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?count=3')->assertOk();
        $seed = $preview->json('data.seed');
        $this->assertIsInt($seed);

        $this->auto($user, 3, ['seed' => $seed])
            ->assertCreated()
            ->assertJsonCount(3, 'data.batches')
            ->assertJsonPath('data.stopped', null)
            ->assertJsonPath('data.batches.0.source', 'auto');

        $pairs = $this->pairs($user);
        // Gửi seed của xem trước thì tạo đúng các cặp đã xem
        $this->assertSame(array_map(fn ($b) => [$b['product']['id'], $b['group']['id']], $preview->json('data.batches')), $pairs);
        // Không lặp sản phẩm / nhóm
        $this->assertCount(3, array_unique(array_column($pairs, 0)));
        $this->assertCount(3, array_unique(array_column($pairs, 1)));
        $this->assertEmpty(array_diff(array_column($pairs, 0), $products));
        $this->assertEmpty(array_diff(array_column($pairs, 1), $groups));
        foreach (array_column($pairs, 0) as $id) {
            $this->assertSame(1, $this->autoCount(Product::query()->findOrFail($id)));
        }
    }

    public function test_inactive_products_are_skipped(): void
    {
        $user = $this->creator();
        foreach (range(1, 3) as $i) {
            $this->group($user, [$this->publishedCreative($user)], "G{$i}");
        }
        $active = $this->product($user);
        $inactive = $this->product($user);

        $this->actingAs($this->creator())->putJson("/api/v1/creator/products/{$inactive->id}/active", ['is_active' => false])->assertNotFound();
        $this->actingAs($user)->putJson("/api/v1/creator/products/{$inactive->id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->actingAs($user)->getJson("/api/v1/creator/products/{$active->id}")->assertJsonPath('data.is_active', true);

        $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?count=3')
            ->assertOk()
            ->assertJsonCount(1, 'data.batches')
            ->assertJsonPath('data.batches.0.product.id', $active->id);

        // Chọn thủ công sản phẩm đang tắt cũng không tạo
        $this->auto($user, 1, ['product_ids' => [$inactive->id]])->assertStatus(422);
        $this->auto($user, 3)->assertCreated()->assertJsonCount(1, 'data.batches');
        $this->assertSame([$active->id], array_column($this->pairs($user), 0));

        // Bật lại thì được tạo
        $this->actingAs($user)->putJson("/api/v1/creator/products/{$inactive->id}/active", ['is_active' => true])->assertOk();
        $this->auto($user, 1, ['product_ids' => [$inactive->id]])->assertCreated();
    }

    public function test_products_and_groups_are_picked_randomly(): void
    {
        $user = $this->creator();
        foreach (range(1, 5) as $i) {
            $this->group($user, [$this->publishedCreative($user)], "G{$i}");
            $this->product($user);
        }

        $picked = array_map(fn (int $seed) => $this->actingAs($user)->getJson("/api/v1/image-batches/auto/preview?count=1&seed={$seed}")
            ->json('data.batches.0'), range(1, 20));

        // Không phải lúc nào cũng sản phẩm / nhóm tạo trước
        $this->assertGreaterThan(1, count(array_unique(array_column(array_column($picked, 'product'), 'id'))));
        $this->assertGreaterThan(1, count(array_unique(array_column(array_column($picked, 'group'), 'id'))));
        // Cùng seed thì cùng kết quả
        $this->assertSame($picked[0], $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?count=1&seed=1')->json('data.batches.0'));
    }

    public function test_count_is_capped_by_fewer_products_or_groups(): void
    {
        $user = $this->creator();
        $this->group($user, [$this->publishedCreative($user)], 'G1');
        $this->group($user, [$this->publishedCreative($user)], 'G2');
        foreach (range(1, 4) as $i) {
            $this->product($user);
        }

        $this->auto($user, 5)
            ->assertCreated()
            ->assertJsonCount(2, 'data.batches')
            ->assertJsonPath('data.requested', 5)
            ->assertJsonPath('data.stopped', 'no_valid_pair');
    }

    public function test_product_image_fills_linked_field_and_invalid_creatives_are_skipped(): void
    {
        $user = $this->creator();
        $ok = $this->publishedCreative($user);
        // Không có trường anh_san_pham
        $noField = $this->publishedCreative($user, config: ['input_schema' => [
            ['key' => 'model_photo', 'label' => 'Ảnh mẫu', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []],
        ]]);
        // Còn trường chữ bắt buộc không có mặc định
        $needsText = $this->publishedCreative($user, config: ['input_schema' => [
            ['key' => 'anh_san_pham', 'label' => 'Ảnh', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []],
            ['key' => 'slogan', 'label' => 'Slogan', 'type' => 'text', 'required' => true, 'sort_order' => 2, 'validation' => []],
        ]]);
        // Trường chữ bắt buộc có mặc định thì dùng mặc định
        $withDefault = $this->publishedCreative($user, config: ['input_schema' => [
            ['key' => 'anh_san_pham', 'label' => 'Ảnh', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []],
            ['key' => 'slogan', 'label' => 'Slogan', 'type' => 'text', 'required' => true, 'sort_order' => 2, 'validation' => [], 'default_value' => 'Sale'],
        ]]);
        $paused = $this->publishedCreative($user);
        $paused->update(['status' => CreativeStatus::Paused]);

        $this->group($user, [$ok, $noField, $needsText, $withDefault, $paused]);
        $product = $this->product($user);

        $this->auto($user, 1)
            ->assertCreated()
            ->assertJsonPath('data.batches.0.image_count', 2);

        $path = $product->images()->first()->path;
        $generations = Generation::query()->orderBy('id')->get();
        $this->assertSame([$ok->id, $withDefault->id], $generations->pluck('creative_id')->all());
        $this->assertSame(['anh_san_pham' => $path], $generations[0]->input_paths);
        $this->assertSame('Sale', $generations[1]->input_values['slogan']);
    }

    public function test_products_pair_with_groups_regardless_of_hashtags(): void
    {
        $user = $this->creator();
        $g1 = $this->group($user, [$this->tag($this->publishedCreative($user), ['giay'])], 'G1');
        $g2 = $this->group($user, [$this->tag($this->publishedCreative($user), ['ao'])], 'G2');

        $shoes = $this->product($user, hashtags: ['tui']);
        // Không có hashtag vẫn tạo
        $plain = $this->product($user, hashtags: []);

        $this->auto($user, 2)->assertCreated()->assertJsonCount(2, 'data.batches');

        $pairs = $this->pairs($user);
        $this->assertEqualsCanonicalizing([$shoes->id, $plain->id], array_column($pairs, 0));
        $this->assertEqualsCanonicalizing([$g1->id, $g2->id], array_column($pairs, 1));
        $this->assertSame(1, $this->autoCount($plain));
    }

    public function test_pair_without_valid_creative_tries_other_group_or_skips_product(): void
    {
        $user = $this->creator();
        $g1 = $this->group($user, [$this->publishedCreative($user)], 'G1');
        // Creative cần ảnh key khác: không sản phẩm nào ghép được
        $this->group($user, [$this->publishedCreative($user, config: ['input_schema' => [
            ['key' => 'model_photo', 'label' => 'Ảnh mẫu', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []],
        ]])], 'G2');
        // Sản phẩm cũ chỉ có ảnh key khác (form mới bắt buộc khung anh_san_pham nên tạo thẳng trong DB)
        $p1 = Product::query()->create(['user_id' => $user->id, 'name' => 'P1']);
        $p1->images()->create(['field_key' => 'other_key', 'path' => 'products/p1.png', 'position' => 0]);
        $p2 = $this->product($user);

        // Seed nào cũng chỉ ra cặp P2 x G1
        foreach (range(1, 10) as $seed) {
            $this->actingAs($user)->getJson("/api/v1/image-batches/auto/preview?count=2&seed={$seed}")
                ->assertJsonCount(1, 'data.batches')
                ->assertJsonPath('data.batches.0.product.id', $p2->id)
                ->assertJsonPath('data.batches.0.group.id', $g1->id);
        }

        $this->auto($user, 2)->assertCreated()->assertJsonCount(1, 'data.batches')->assertJsonPath('data.stopped', 'no_valid_pair');
        $this->assertSame([[$p2->id, $g1->id]], $this->pairs($user));
        $this->assertSame(0, $this->autoCount($p1));
        $this->assertSame(1, $this->autoCount($p2));

        $this->actingAs($user)->deleteJson("/api/v1/creator/products/{$p2->id}")->assertNoContent();
        $this->auto($user, 1)->assertStatus(422)->assertJsonPath('code', 'AUTO_NOTHING_TO_CREATE');
        $this->actingAs($this->creator())->postJson('/api/v1/image-batches/auto', ['count' => 1, 'idempotency_key' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'AUTO_NOTHING_TO_CREATE');
    }

    public function test_same_idempotency_key_returns_same_batches(): void
    {
        $user = $this->creator();
        $this->group($user, [$this->publishedCreative($user)], 'G1');
        $this->group($user, [$this->publishedCreative($user)], 'G2');
        [$p1, $p2] = [$this->product($user), $this->product($user)];

        $first = $this->auto($user, 2, ['idempotency_key' => 'same'])->json('data.batches.*.id');
        $second = $this->auto($user, 2, ['idempotency_key' => 'same'])->json('data.batches.*.id');

        $this->assertCount(2, $first);
        $this->assertSame($first, $second);
        $this->assertSame([1, 1], [$this->autoCount($p1), $this->autoCount($p2)]);
    }

    /** $n ảnh đã tạo cho $product bằng $creative (generations.product_id), ảnh đang tạo cũng tính. */
    private function madeImages(User $user, AiCreative $creative, Product $product, int $n): void
    {
        $this->generate($user, $creative, quantity: $n, extra: ['product_id' => $product->id, 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(202);
    }

    /** @return array<int, array{0: int, 1: int}> cặp [product_id, group_id] của bản xem trước với $seed */
    private function previewPairs(User $user, int $count, int $seed): array
    {
        return array_map(
            fn (array $b) => [$b['product']['id'], $b['group']['id']],
            $this->actingAs($user)->getJson("/api/v1/image-batches/auto/preview?count={$count}&seed={$seed}")->assertOk()->json('data.batches'),
        );
    }

    public function test_products_with_fewest_images_come_first(): void
    {
        $user = $this->creator();
        $creative = $this->publishedCreative($user);
        foreach (['G1', 'G2', 'G3'] as $name) {
            $this->group($user, [$this->publishedCreative($user)], $name);
        }
        [$many, $none, $one] = [$this->product($user), $this->product($user), $this->product($user)];
        $this->madeImages($user, $creative, $many, 2);
        $this->madeImages($user, $creative, $one, 1);

        foreach ([1, 2, 3, 42, 999] as $seed) {
            $this->assertSame([$none->id, $one->id, $many->id], array_column($this->previewPairs($user, 3, $seed), 0));
        }
    }

    public function test_products_with_same_image_count_are_picked_randomly(): void
    {
        $user = $this->creator();
        $this->group($user, [$this->publishedCreative($user)], 'G1');
        foreach (range(1, 5) as $_) {
            $this->product($user);
        }

        $firsts = array_map(fn (int $seed) => $this->previewPairs($user, 1, $seed)[0][0], range(1, 20));

        $this->assertGreaterThan(1, count(array_unique($firsts)));
    }

    public function test_each_product_gets_group_with_fewest_uses_for_that_product(): void
    {
        $user = $this->creator();
        [$c1, $c2] = [$this->publishedCreative($user), $this->publishedCreative($user)];
        $this->group($user, [$c1], 'G1');
        $g2 = $this->group($user, [$c2], 'G2');
        $product = $this->product($user);
        $other = $this->product($user);
        $this->madeImages($user, $c1, $product, 2); // sản phẩm này đã dùng G1 nhiều
        $this->madeImages($user, $c2, $other, 3);   // lượt tạo của sản phẩm khác không tính cho $product

        foreach ([1, 2, 3, 42, 999] as $seed) {
            $pairs = collect($this->previewPairs($user, 2, $seed))->keyBy(0);
            $this->assertSame($g2->id, $pairs[$product->id][1]);
        }
    }

    public function test_groups_with_same_uses_are_picked_randomly(): void
    {
        $user = $this->creator();
        foreach (range(1, 4) as $i) {
            $this->group($user, [$this->publishedCreative($user)], "G{$i}");
        }
        $this->product($user);

        $groups = array_map(fn (int $seed) => $this->previewPairs($user, 1, $seed)[0][1], range(1, 20));

        $this->assertGreaterThan(1, count(array_unique($groups)));
    }

    public function test_preview_creates_nothing(): void
    {
        $user = $this->creator();
        $creative = $this->publishedCreative($user);
        $this->group($user, [$creative], 'G1');
        $this->product($user, name: 'Áo');

        $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?count=3')
            ->assertOk()
            ->assertJsonCount(1, 'data.batches')
            ->assertJsonPath('data.batches.0.product.name', 'Áo')
            ->assertJsonPath('data.batches.0.group.name', 'G1')
            ->assertJsonPath('data.batches.0.creatives.0.id', $creative->id)
            ->assertJsonMissingPath('data.batches.0.cost');

        $this->assertSame(0, ImageBatch::query()->count());
    }
}
