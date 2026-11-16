<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\ImageBatch;
use App\Models\Product;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Lô phối đồ (mode=outfit): POST /image-batches/auto, worker loại outfit, tạo lại ảnh lỗi. */
class OutfitBatchTest extends TestCase
{
    private function product(User $owner, string $name): Product
    {
        $product = Product::query()->create(['user_id' => $owner->id, 'name' => $name]);
        $product->images()->create(['field_key' => 'anh_san_pham', 'path' => "products/{$name}.png", 'position' => 1]);

        return $product;
    }

    /** @return array{0: Worker, 1: string} */
    private function outfitWorker(User $user): array
    {
        [$worker, $token] = Worker::register('phoi-do-'.Str::random(6), Worker::TYPE_OUTFIT);
        $worker->users()->attach($user->id);

        return [$worker, $token];
    }

    private function createBatches(User $user, Worker $worker, int $count = 2, int $images = 3)
    {
        return $this->actingAs($user)->postJson('/api/v1/image-batches/auto', [
            'mode' => 'outfit', 'count' => $count, 'images_per_batch' => $images,
            'worker_id' => $worker->id, 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_creates_outfit_batches_with_cover_and_outfit_images(): void
    {
        $user = $this->buyer();
        $a = $this->product($user, 'giay-a');
        $b = $this->product($user, 'giay-b');
        [$worker] = $this->outfitWorker($user);

        $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?mode=outfit&count=5&images_per_batch=4')
            ->assertOk()->assertJsonCount(2, 'data.batches')->assertJsonPath('data.images_per_batch', 4);

        $res = $this->createBatches($user, $worker)->assertCreated()->assertJsonCount(2, 'data.batches');
        $this->assertSame('outfit', $res->json('data.batches.0.source'));
        $this->assertSame(3, $res->json('data.batches.0.image_count'));

        $batch = ImageBatch::query()->with('images.generation')->findOrFail($res->json('data.batches.0.id'));
        $kinds = $batch->images->sortBy('position')->map(fn ($i) => $i->generation->kind)->values()->all();
        $this->assertSame([Generation::KIND_OUTFIT_COVER, Generation::KIND_OUTFIT, Generation::KIND_OUTFIT], $kinds);
        $this->assertSame($worker->id, $batch->images->first()->generation->worker_id);
        $this->assertSame(['anh_san_pham' => 'products/giay-a.png'], $batch->images->first()->generation->input_paths);

        // Lần sau: sản phẩm ít lô phối đồ nhất trước (đều 1 lô) -> theo id
        $this->createBatches($user, $worker, 1)->assertCreated()->assertJsonPath('data.batches.0.product.id', $a->id);
        $this->createBatches($user, $worker, 1)->assertCreated()->assertJsonPath('data.batches.0.product.id', $b->id);
    }

    public function test_without_product_creates_count_batches_and_cover_waits_for_outfit(): void
    {
        $user = $this->buyer(); // không có sản phẩm nào vẫn tạo được
        [$worker, $token] = $this->outfitWorker($user);

        $this->actingAs($user)->getJson('/api/v1/image-batches/auto/preview?mode=outfit&count=3&images_per_batch=3&use_product=0')
            ->assertOk()->assertJsonCount(3, 'data.batches')->assertJsonPath('data.use_product', false)
            ->assertJsonPath('data.batches.0.product', null);

        $res = $this->actingAs($user)->postJson('/api/v1/image-batches/auto', [
            'mode' => 'outfit', 'count' => 2, 'images_per_batch' => 3, 'use_product' => false,
            'worker_id' => $worker->id, 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonCount(2, 'data.batches');
        $batch = ImageBatch::query()->with('images.generation')->findOrFail($res->json('data.batches.0.id'));
        $this->assertSame(0, $batch->images->first()->generation->product_id);
        $this->assertSame([], $batch->images->first()->generation->input_paths);

        // Bìa (ảnh 1) chưa được giao khi lô chưa có ảnh outfit nào xong -> worker nhận ảnh outfit trước
        $job = $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data');
        $this->assertSame('outfit', $job['kind']);
        $this->assertFalse($job['use_product']);
        $this->assertNull($job['product']);
        $this->assertSame([], $job['history_images']);
        $this->completeJob($token, $job['id'])->assertOk();

        // Lô 1 đã có 1 ảnh outfit xong -> giờ giao bìa lô 1, kèm ảnh outfit đã xong của lô
        $cover = $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data');
        $this->assertSame('outfit_cover', $cover['kind']);
        $this->assertSame($batch->id, $cover['batch']['id']);
        $this->assertCount(1, $cover['outfit_images']);
        $this->assertSame(2, $cover['outfit_images'][0]['position']);
        $this->completeJob($token, $cover['id'])->assertOk();

        // Ảnh outfit còn lại của lô 1 lỗi hết: bìa vẫn đã xong; lô 2: outfit lỗi hết thì bìa vẫn được giao (worker báo lỗi)
        $next = $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data');
        $this->assertSame('outfit', $next['kind']);
        $this->failJob($token, $next['id'], 'CHATGPT_LIMIT')->assertOk();
        foreach ([1, 2] as $_) {
            $j = $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data');
            $this->assertSame('outfit', $j['kind']);
            $this->failJob($token, $j['id'], 'CHATGPT_LIMIT')->assertOk();
        }
        $last = $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data');
        $this->assertSame('outfit_cover', $last['kind']);
        $this->assertSame([], $last['outfit_images']);
    }

    public function test_requires_outfit_worker(): void
    {
        $user = $this->buyer();
        $this->product($user, 'giay-a');
        [$imageWorker] = Worker::register('anh-'.Str::random(4), Worker::TYPE_IMAGE);
        $imageWorker->users()->attach($user->id);

        $this->createBatches($user, $imageWorker)->assertUnprocessable()->assertJsonValidationErrors('worker_id');
    }

    public function test_only_outfit_worker_claims_outfit_jobs_and_gets_product_payload(): void
    {
        $user = $this->buyer();
        $this->product($user, 'giay-a');
        [$worker, $token] = $this->outfitWorker($user);
        $this->createBatches($user, $worker, 1, 2)->assertCreated();

        [$imageWorker, $imageToken] = Worker::register('anh-'.Str::random(4), Worker::TYPE_IMAGE);
        $imageWorker->users()->attach($user->id);
        $this->withToken($imageToken)->getJson('/api/v1/jobs')->assertNoContent();

        $job = $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data');
        $this->assertSame('outfit_cover', $job['kind']);
        $this->assertSame(1, $job['batch']['position']);
        $this->assertSame(2, $job['batch']['total']);
        $this->assertSame('anh_san_pham', $job['product']['images'][0]['field_key']);
        $this->assertTrue($job['use_product']);
        $this->completeJob($token, $job['id'])->assertOk();

        $job2 = $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data');
        $this->assertSame('outfit', $job2['kind']);
        // Ảnh lịch sử chỉ lấy ảnh creative: ảnh bìa phối đồ vừa xong không tính
        $this->assertSame([], $job2['history_images']);
        $this->failJob($token, $job2['id'], 'CHATGPT_LIMIT')->assertOk();
        $this->withToken($token)->getJson('/api/v1/jobs')->assertNoContent();
    }

    public function test_retry_failed_requeues_failed_images_and_blocks_input_editing(): void
    {
        $user = $this->buyer();
        $this->product($user, 'giay-a');
        [$worker, $token] = $this->outfitWorker($user);
        $batchId = $this->createBatches($user, $worker, 1, 2)->assertCreated()->json('data.batches.0.id');

        foreach (['CHATGPT_LIMIT', 'CHATGPT_LIMIT'] as $code) {
            $id = $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data.id');
            $this->failJob($token, $id, $code)->assertOk();
        }
        $detail = $this->actingAs($user)->getJson("/api/v1/image-batches/{$batchId}")->assertOk();
        $this->assertSame('CHATGPT_LIMIT', $detail->json('data.images.1.error_code'));
        $this->assertSame('outfit', $detail->json('data.images.1.kind'));
        $imageId = $detail->json('data.images.1.id');

        $this->actingAs($user)->getJson("/api/v1/image-batches/{$batchId}/images/{$imageId}/inputs")->assertStatus(409);

        $this->actingAs($user)->postJson("/api/v1/image-batches/{$batchId}/retry-failed", ['idempotency_key' => 'r1'])
            ->assertStatus(202)->assertJsonPath('data.requeued', 2);
        $this->assertNotNull($this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->json('data.id'));

        // "Tạo lại" từng ảnh cũng chạy với ảnh phối đồ (ảnh đã xong / lỗi)
        $other = $this->actingAs($user)->getJson("/api/v1/image-batches/{$batchId}")->json('data.images.1.id');
        $this->actingAs($user)->postJson("/api/v1/image-batches/{$batchId}/images/{$other}/regenerate", ['idempotency_key' => 'g1'])
            ->assertStatus(409); // ảnh này đang ở hàng chờ (chưa xong) -> chưa tạo lại được
    }

    public function test_system_creative_hidden_from_creator_list(): void
    {
        $user = $this->buyer();
        $this->product($user, 'giay-a');
        [$worker] = $this->outfitWorker($user);
        $this->createBatches($user, $worker, 1)->assertCreated();

        $creator = $this->creator();
        $this->actingAs($creator)->getJson('/api/v1/creator/creatives')->assertOk()
            ->assertJsonMissing(['slug' => 'he-thong-phoi-do']);
    }

    /** Ảnh lô phối đồ (bìa + outfit) tạo xong. */
    private function completeOutfitImages(): void
    {
        GenerationOutput::query()
            ->whereHas('generation', fn ($q) => $q->whereIn('kind', Generation::OUTFIT_KINDS))
            ->update(['status' => OutputStatus::Completed, 'image_path' => 'outputs/outfit.png']);
    }

    public function test_history_has_separate_outfit_tab(): void
    {
        $user = $this->buyer();
        $this->product($user, 'giay-a');
        [$worker] = $this->outfitWorker($user);
        $this->createBatches($user, $worker, 1, 3)->assertCreated();
        $this->completeOutfitImages();
        $this->generate($user, $this->publishedCreative($this->creator()), 1);

        // Tab "Phối đồ": bìa + 2 ảnh outfit; tab "Đang hoạt động": chỉ ảnh creative
        $outfit = $this->actingAs($user)->getJson('/api/v1/generations?tab=outfit')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['outfit_cover', 'outfit', 'outfit'], array_column($outfit, 'kind'));
        $active = $this->getJson('/api/v1/generations?tab=active')->assertOk()->json('data');
        $this->assertSame(['creative'], array_column($active, 'kind'));
    }
}
