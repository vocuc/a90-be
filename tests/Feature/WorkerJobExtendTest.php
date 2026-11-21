<?php

namespace Tests\Feature;

use App\Models\WorkerJob;
use App\Models\Product;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Tests\TestCase;

/** POST /api/v1/worker-jobs/{id}/extend: worker gia hạn job đang làm (worker phối đồ Python, D:\FlowKit\phoi-do-worker). */
class WorkerJobExtendTest extends TestCase
{
    /** @return array{0: Worker, 1: string} */
    private function outfitWorkerWithJob(User $user): array
    {
        $product = Product::query()->create(['user_id' => $user->id, 'name' => 'giay-a']);
        $product->images()->create(['field_key' => 'anh_san_pham', 'path' => 'products/giay-a.png', 'position' => 1]);
        [$worker, $token] = Worker::register('phoi-do-'.Str::random(6), Worker::TYPE_OUTFIT);
        $worker->users()->attach($user->id);
        $this->actingAs($user)->postJson('/api/v1/image-batches/auto', [
            'mode' => 'outfit', 'count' => 1, 'images_per_batch' => 2,
            'worker_id' => $worker->id, 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        return [$worker, $token];
    }

    public function test_extend_pushes_lease_forward(): void
    {
        $user = $this->buyer();
        [, $token] = $this->outfitWorkerWithJob($user);
        $id = $this->withToken($token)->getJson('/api/v1/worker-jobs')->assertOk()->json('data.id');
        WorkerJob::query()->whereKey($id)->update(['lease_expires_at' => now()->addMinute()]);

        $this->travel(5)->minutes();
        $res = $this->withToken($token)->postJson("/api/v1/worker-jobs/{$id}/extend")->assertOk()->assertJsonPath('data.id', $id);

        $lease = WorkerJob::query()->findOrFail($id)->lease_expires_at;
        $this->assertTrue($lease->greaterThan(now()->addMinutes(config('ai90.worker.lease_minutes') - 1)));
        $this->assertNotNull($res->json('data.lease_expires_at'));
        $this->completeJob($token, $id)->assertOk();
    }

    public function test_extend_rejects_job_of_other_worker_or_finished_job(): void
    {
        $user = $this->buyer();
        [, $token] = $this->outfitWorkerWithJob($user);
        [$other, $otherToken] = Worker::register('phoi-do-'.Str::random(6), Worker::TYPE_OUTFIT);
        $other->users()->attach($user->id);
        $id = $this->withToken($token)->getJson('/api/v1/worker-jobs')->assertOk()->json('data.id');

        $this->withToken($otherToken)->postJson("/api/v1/worker-jobs/{$id}/extend")->assertStatus(409);

        $this->completeJob($token, $id)->assertOk();
        $this->withToken($token)->postJson("/api/v1/worker-jobs/{$id}/extend")->assertStatus(409);
        $this->withToken($token)->postJson('/api/v1/worker-jobs/999999/extend')->assertNotFound();
    }
}
