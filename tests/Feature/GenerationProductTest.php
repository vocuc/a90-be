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
        $buyer = $this->buyer();
        $product = Product::query()->create(['user_id' => $buyer->id, 'name' => 'Áo thun']);

        $withProduct = $this->generate($buyer, $creative, extra: ['product_id' => $product->id])->assertStatus(202)->json('data.id');
        $without = $this->generate($buyer, $creative, extra: ['idempotency_key' => 'k-2'])->assertStatus(202)->json('data.id');

        $this->assertSame($product->id, Generation::query()->findOrFail($withProduct)->product_id);
        $this->assertSame(0, Generation::query()->findOrFail($without)->product_id);
    }

    public function test_product_list_shows_generated_image_count(): void
    {
        $shop = $this->creator();
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
        $buyer = $this->buyer();
        $foreign = Product::query()->create(['user_id' => $this->buyer()->id, 'name' => 'Của người khác']);

        $this->generate($buyer, $creative, extra: ['product_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.product_id.0', 'Sản phẩm không tồn tại.');
        $this->assertSame(0, Generation::query()->count());
    }

    public function test_manual_batch_saves_product_only_on_generations(): void
    {
        $creative = $this->publishedCreative($this->creator()); // max_outputs = 5 -> 7 ảnh = 2 generation
        $buyer = $this->buyer();
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
        $buyer = $this->buyer();
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

    public function test_product_prompt_note_is_appended_to_prompt(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer();
        $product = Product::query()->create(['user_id' => $buyer->id, 'name' => 'Áo thun', 'prompt_note' => 'Nền trắng, không chữ.']);
        $empty = Product::query()->create(['user_id' => $buyer->id, 'name' => 'Chưa có yêu cầu']);

        $base = $this->generate($buyer, $creative, extra: ['idempotency_key' => 'k-1'])->json('data.id');
        $withNote = $this->generate($buyer, $creative, extra: ['idempotency_key' => 'k-2', 'product_id' => $product->id])->json('data.id');
        $withoutNote = $this->generate($buyer, $creative, extra: ['idempotency_key' => 'k-3', 'product_id' => $empty->id])->json('data.id');

        $prompt = fn ($id) => Generation::query()->findOrFail($id)->prompt_snapshot;
        $this->assertSame($prompt($base)."\n\nNền trắng, không chữ.", $prompt($withNote));
        $this->assertSame($prompt($base), $prompt($withoutNote));
    }

    public function test_product_api_saves_and_clears_prompt_note(): void
    {
        $user = $this->creator();
        $image = fn () => ['file' => UploadedFile::fake()->image('p.png', 32, 32), 'field_key' => 'product_image'];

        $id = $this->actingAs($user)->post('/api/v1/creator/products', [
            'prompt_note' => '  Giữ nguyên logo.  ',
            'images' => [$image()],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.prompt_note', 'Giữ nguyên logo.')->json('data.id');

        $this->actingAs($user)->post("/api/v1/creator/products/{$id}", [
            'prompt_note' => str_repeat('a', Product::PROMPT_NOTE_MAX + 1),
            'images' => [$image()],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('prompt_note');

        // Không gửi / gửi rỗng -> bỏ yêu cầu
        $this->actingAs($user)->post("/api/v1/creator/products/{$id}", [
            'prompt_note' => '   ',
            'images' => [$image()],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.prompt_note', null);
    }
}
