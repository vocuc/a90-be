<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ImageBatch;
use App\Models\ShopStaff;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Worker tạo video: GET /api/v1/video-images?count=N lấy ảnh ngẫu nhiên trong các lô của shop gắn với worker. */
class WorkerVideoImageTest extends TestCase
{
    /** Lô 1 mẫu, $quantity ảnh đã tạo xong. */
    private function completedBatch(User $user, int $quantity): ImageBatch
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

        return ImageBatch::query()->findOrFail($id);
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
        $this->completedBatch($shop, 2);
        $this->completedBatch($shop, 1);
        [, $token] = $this->videoWorker($shop);

        $data = $this->images($token, 3)->assertOk()
            ->assertJsonPath('data.account.id', $shop->id)
            ->assertJsonCount(3, 'data.images')
            ->json('data.images');

        $this->assertCount(3, array_unique(array_column($data, 'id')));
        foreach ($data as $image) {
            $this->assertNotEmpty($image['url']);
        }
        // Ảnh dùng lại được: xin tiếp vẫn có
        $this->images($token, 2)->assertOk()->assertJsonCount(2, 'data.images');
    }

    public function test_not_enough_images_reports_available(): void
    {
        $shop = $this->buyer(credit: 100000);
        $this->completedBatch($shop, 2);
        [, $token] = $this->videoWorker($shop);

        $this->images($token, 3)->assertStatus(422)->assertJsonFragment(['NOT_ENOUGH_IMAGES']);
        $this->images($token, 3)->assertJsonFragment(['available' => 2]);
    }

    public function test_only_images_of_assigned_shop_and_its_staff(): void
    {
        $owner = $this->buyer(credit: 100000);
        $staff = $this->buyer(credit: 100000);
        $other = $this->buyer(credit: 100000);
        ShopStaff::query()->create(['owner_id' => $owner->id, 'user_id' => $staff->id]);

        $ownBatch = $this->completedBatch($owner, 1);
        $staffBatch = $this->completedBatch($staff, 1);
        $staffBatch->forceFill(['shop_owner_id' => $owner->id])->save();
        $this->completedBatch($other, 2);

        $expected = collect([$ownBatch, $staffBatch])->flatMap(fn (ImageBatch $b) => $b->images()->pluck('output_id'))->sort()->values()->all();

        // Gắn chủ shop hay nhân viên đều lấy ảnh của cả shop
        foreach ([$owner, $staff] as $user) {
            [, $token] = $this->videoWorker($user);
            $ids = collect($this->images($token, 2)->assertOk()->json('data.images'))->pluck('id')->sort()->values()->all();
            $this->assertSame($expected, $ids);
            $this->images($token, 3)->assertStatus(422);
        }
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
