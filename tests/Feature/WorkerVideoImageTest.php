<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ImageBatch;
use App\Models\Product;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Worker tạo video: GET /api/v1/video-images?count=N lấy ảnh ngẫu nhiên của 1 sản phẩm trong các lô của shop gắn với worker. */
class WorkerVideoImageTest extends TestCase
{
    private function product(User $owner, string $name = 'SP'): Product
    {
        return Product::query()->create(['user_id' => $owner->id, 'name' => $name]);
    }

    /** Lô 1 mẫu, $quantity ảnh đã tạo xong, gắn sản phẩm $product (null = lô tạo tay). */
    private function completedBatch(User $user, int $quantity, ?Product $product = null): ImageBatch
    {
        $id = $this->actingAs($user)->post('/api/v1/image-batches', [
            'items' => [[
                'creative_id' => $this->publishedCreative($this->creator())->id,
                'quantity' => $quantity,
                'inputs' => ['product_image' => UploadedFile::fake()->image('product.png', 64, 64), 'aspectRatio' => '1:1'],
            ]],
            'idempotency_key' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->runWorker(array_fill(0, $quantity, null));
        $batch = ImageBatch::query()->findOrFail($id);
        $batch->forceFill(['product_id' => $product?->id])->save();

        return $batch;
    }

    /** @return array{0: Worker, 1: string} */
    private function videoWorker(?User $user): array
    {
        [$worker, $token] = Worker::register('video-'.Str::random(6));
        $worker->update(['video_user_id' => $user?->id]);

        return [$worker, $token];
    }

    private function images(string $token, int $count)
    {
        return $this->withToken($token)->getJson("/api/v1/video-images?count={$count}");
    }

    private function admin(): User
    {
        $admin = $this->buyer();
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    public function test_returns_exact_count_of_shop_images(): void
    {
        $shop = $this->buyer(credit: 100000);
        $product = $this->product($shop);
        $this->completedBatch($shop, 2, $product);
        $this->completedBatch($shop, 1, $product);
        [, $token] = $this->videoWorker($shop);

        $data = $this->images($token, 3)->assertOk()
            ->assertJsonPath('data.account.id', $shop->id)
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonCount(3, 'data.images')
            ->json('data.images');

        $this->assertCount(3, array_unique(array_column($data, 'id')));
        foreach ($data as $image) {
            $this->assertNotEmpty($image['url']);
        }
        // Ảnh dùng lại được: xin tiếp vẫn có
        $this->images($token, 2)->assertOk()->assertJsonCount(2, 'data.images');
    }

    public function test_disabled_images_are_not_sent_to_worker(): void
    {
        $shop = $this->buyer(credit: 100000);
        $batch = $this->completedBatch($shop, 3, $this->product($shop));
        $image = $batch->images()->firstOrFail();
        $this->actingAs($shop)->postJson("/api/v1/generations/{$image->generation_id}/outputs/{$image->output_id}/disable")
            ->assertNoContent();
        [, $token] = $this->videoWorker($shop);

        $this->images($token, 3)->assertStatus(422)->assertJsonFragment(['available' => 2]);
        $ids = array_column($this->images($token, 2)->assertOk()->json('data.images'), 'id');
        $this->assertNotContains($image->output_id, $ids);

        // Lô vẫn hiển thị đủ ảnh, ảnh đã tắt có cờ disabled
        $this->actingAs($shop)->getJson("/api/v1/image-batches/{$batch->id}")->assertOk()
            ->assertJsonCount(3, 'data.images')
            ->assertJsonPath('data.images.0.disabled', true);
    }

    public function test_not_enough_images_reports_available(): void
    {
        $shop = $this->buyer(credit: 100000);
        $this->completedBatch($shop, 2, $this->product($shop, 'A'));
        $this->completedBatch($shop, 1, $this->product($shop, 'B'));
        [, $token] = $this->videoWorker($shop);

        // Tổng 3 ảnh nhưng không sản phẩm nào đủ 3
        $this->images($token, 3)->assertStatus(422)->assertJsonFragment(['NOT_ENOUGH_IMAGES']);
        $this->images($token, 3)->assertJsonFragment(['available' => 2]);
    }

    public function test_only_images_of_assigned_shop(): void
    {
        $owner = $this->buyer(credit: 100000);
        $other = $this->buyer(credit: 100000);

        $product = $this->product($owner);
        $batches = [$this->completedBatch($owner, 1, $product), $this->completedBatch($owner, 1, $product)];
        $this->completedBatch($other, 2, $this->product($other));

        $expected = collect($batches)->flatMap(fn (ImageBatch $b) => $b->images()->pluck('output_id'))->sort()->values()->all();

        [, $token] = $this->videoWorker($owner);
        $ids = collect($this->images($token, 2)->assertOk()->json('data.images'))->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $ids);
        $this->images($token, 3)->assertStatus(422);
    }

    public function test_images_come_from_a_single_product(): void
    {
        $shop = $this->buyer(credit: 100000);
        $a = $this->product($shop, 'A');
        $b = $this->product($shop, 'B');
        $idsOf = fn (Product $p) => ImageBatch::query()->where('product_id', $p->id)->get()
            ->flatMap(fn (ImageBatch $batch) => $batch->images()->pluck('output_id'))->all();
        $this->completedBatch($shop, 2, $a);
        $this->completedBatch($shop, 2, $b);
        $this->completedBatch($shop, 1, $this->product($shop, 'C')); // không đủ 2 ảnh -> không bao giờ được chọn
        [, $token] = $this->videoWorker($shop);

        $seen = [];
        for ($i = 0; $i < 10; $i++) {
            $response = $this->images($token, 2)->assertOk();
            $productId = $response->json('data.product.id');
            $this->assertContains($productId, [$a->id, $b->id]);
            $expected = $idsOf($productId === $a->id ? $a : $b);
            foreach ($response->json('data.images') as $image) {
                $this->assertContains($image['id'], $expected);
            }
            $seen[$productId] = true;
        }
        $this->assertNotEmpty($seen);
    }

    public function test_batches_without_product_are_ignored(): void
    {
        $shop = $this->buyer(credit: 100000);
        $this->completedBatch($shop, 3); // lô tạo tay
        [, $token] = $this->videoWorker($shop);

        $this->images($token, 1)->assertStatus(422)->assertJsonFragment(['available' => 0]);
    }

    public function test_worker_without_video_account_is_rejected(): void
    {
        [, $token] = $this->videoWorker(null);
        $this->images($token, 1)->assertStatus(409)->assertJsonFragment(['VIDEO_ACCOUNT_NOT_ASSIGNED']);
        $this->withoutToken()->getJson('/api/v1/video-images?count=1')->assertStatus(401);
    }

    public function test_count_is_validated(): void
    {
        [, $token] = $this->videoWorker($this->buyer());
        $this->images($token, 0)->assertStatus(422)->assertJsonValidationErrors('count');
        $this->withToken($token)->getJson('/api/v1/video-images')->assertStatus(422)->assertJsonValidationErrors('count');
        $this->images($token, 51)->assertStatus(422)->assertJsonValidationErrors('count');
    }

    public function test_admin_sets_and_clears_video_account(): void
    {
        [$worker] = Worker::register('may-video');
        $shop = $this->buyer();
        $shop2 = $this->buyer();
        $this->actingAs($this->admin());

        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee('Tài khoản tạo video');

        $this->put("/admin/workers/{$worker->id}/video-user", ['video_email' => "  {$shop->email} "])->assertSessionHas('success');
        $this->assertSame($shop->id, $worker->fresh()->video_user_id);
        $this->get('/admin/workers')->assertOk()->assertSee("Tạo video: {$shop->email}", false);

        // Mỗi worker 1 tài khoản: gắn tài khoản khác thay tài khoản cũ
        $this->put("/admin/workers/{$worker->id}/video-user", ['video_email' => $shop2->email])->assertSessionHas('success');
        $this->assertSame($shop2->id, $worker->fresh()->video_user_id);

        $this->put("/admin/workers/{$worker->id}/video-user", ['video_email' => 'khong-co@example.com'])->assertSessionHasErrors('video_email');
        $this->assertSame($shop2->id, $worker->fresh()->video_user_id);

        $this->delete("/admin/workers/{$worker->id}/video-user")->assertSessionHas('success');
        $this->assertNull($worker->fresh()->video_user_id);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.set_video_user')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.clear_video_user')->exists());
    }
}
