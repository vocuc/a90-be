<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\ImageBatch;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** generations.product_id: sản phẩm ảnh được tạo cho, 0 = không gắn sản phẩm. */
class GenerationProductTest extends TestCase
{
    public function test_single_generation_saves_product_or_zero(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer(credit: 5000);
        $product = Product::query()->create(['user_id' => $buyer->id, 'name' => 'Áo thun']);

        $withProduct = $this->generate($buyer, $creative, extra: ['product_id' => $product->id])->assertStatus(202)->json('data.id');
        $without = $this->generate($buyer, $creative, extra: ['idempotency_key' => 'k-2'])->assertStatus(202)->json('data.id');

        $this->assertSame($product->id, Generation::query()->findOrFail($withProduct)->product_id);
        $this->assertSame(0, Generation::query()->findOrFail($without)->product_id);
    }

    public function test_product_list_shows_generated_image_count(): void
    {
        $shop = $this->creator(credit: 10000);
        $creative = $this->publishedCreative($this->creator());
        $product = Product::query()->create(['user_id' => $shop->id, 'name' => 'Áo thun']);
        $other = Product::query()->create(['user_id' => $shop->id, 'name' => 'Chưa có ảnh']);
        $this->generate($shop, $creative, quantity: 3, extra: ['product_id' => $product->id])->assertStatus(202);
        $this->generate($shop, $creative, extra: ['idempotency_key' => 'k-2'])->assertStatus(202); // không gắn sản phẩm
        // 2 ảnh xong, 1 ảnh lỗi (không tính), ảnh không gắn sản phẩm xong
        $this->runWorker([null, null, 'TIMEOUT', null]);
        Generation::query()->where('product_id', $product->id)->firstOrFail()->outputs()->where('status', 'completed')->first()
            ->update(['disabled_at' => now()]); // ảnh đã tắt không tính

        $data = collect($this->actingAs($shop)->getJson('/api/v1/creator/products')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(1, $data[$product->id]['generated_image_count']);
        $this->assertSame(0, $data[$other->id]['generated_image_count']);
        $this->actingAs($shop)->getJson("/api/v1/creator/products/{$product->id}")->assertJsonPath('data.generated_image_count', 1);
    }

    public function test_rejects_product_of_other_user(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer(credit: 5000);
        $foreign = Product::query()->create(['user_id' => $this->buyer()->id, 'name' => 'Của người khác']);

        $this->generate($buyer, $creative, extra: ['product_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.product_id.0', 'Sản phẩm không tồn tại.');
        $this->assertSame(0, Generation::query()->count());
    }

    public function test_manual_batch_saves_product_only_on_generations(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000); // max_outputs = 5 -> 7 ảnh = 2 generation
        $buyer = $this->buyer(credit: 20000);
        $product = Product::query()->create(['user_id' => $buyer->id, 'name' => 'Áo thun']);

        $this->actingAs($buyer)->post('/api/v1/image-batches', [
            'items' => [[
                'creative_id' => $creative->id,
                'quantity' => 7,
                'inputs' => ['product_image' => UploadedFile::fake()->image('product.png', 64, 64), 'aspectRatio' => '1:1'],
            ]],
            'idempotency_key' => 'batch-1',
            'product_id' => $product->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertNull(ImageBatch::query()->sole()->product_id);
        $this->assertSame([$product->id, $product->id], Generation::query()->pluck('product_id')->all());
    }

    public function test_retry_keeps_product(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer(credit: 5000);
        $product = Product::query()->create(['user_id' => $buyer->id, 'name' => 'Áo thun']);
        $id = $this->generate($buyer, $creative, extra: ['product_id' => $product->id])->json('data.id');
        [, $token] = \App\Models\Worker::register('anh', \App\Models\Worker::TYPE_IMAGE);
        $job = $this->claimJob($token)->json('data.id');
        $this->withToken($token)->postJson("/api/v1/jobs/{$job}", ['status' => 'failed', 'error_code' => 'TIMEOUT'])->assertOk();

        $retryId = $this->actingAs($buyer)->postJson("/api/v1/generations/{$id}/retry", [
            'output_ids' => [$job],
            'idempotency_key' => 'retry-1',
        ])->assertStatus(202)->json('data.id');

        $this->assertSame($product->id, Generation::query()->findOrFail($retryId)->product_id);
    }
}
