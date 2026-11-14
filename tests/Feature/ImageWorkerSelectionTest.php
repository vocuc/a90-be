<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Chọn worker tạo ảnh khi tạo ảnh / tạo lô (note/chon-worker-tao-anh.txt ở repo frontend). */
class ImageWorkerSelectionTest extends TestCase
{
    public function test_lists_only_active_image_workers_assigned_to_user_without_token(): void
    {
        $buyer = $this->buyer();
        [$b] = Worker::register('b-anh', Worker::TYPE_IMAGE);
        [$a] = Worker::register('a-anh', Worker::TYPE_IMAGE);
        [$publish] = Worker::register('dang-bai', Worker::TYPE_PUBLISH);
        [$off] = Worker::register('tat', Worker::TYPE_IMAGE);
        [$other] = Worker::register('khac', Worker::TYPE_IMAGE);
        foreach ([$a, $b, $publish, $off] as $w) {
            $w->users()->attach($buyer->id);
        }
        $off->update(['is_active' => false]);

        $data = $this->actingAs($buyer)->getJson('/api/v1/image-workers')->assertOk()->json('data');

        $this->assertSame(['a-anh', 'b-anh'], array_column($data, 'name'));
        $this->assertSame(['id', 'name', 'online', 'last_seen_at'], array_keys($data[0]));
    }

    public function test_lists_outfit_workers_with_type_param(): void
    {
        $buyer = $this->buyer();
        [$img] = Worker::register('anh', Worker::TYPE_IMAGE);
        [$outfit] = Worker::register('phoi-do', Worker::TYPE_OUTFIT);
        foreach ([$img, $outfit] as $w) {
            $w->users()->attach($buyer->id);
        }

        $data = $this->actingAs($buyer)->getJson('/api/v1/image-workers?type=outfit')->assertOk()->json('data');
        $this->assertSame(['phoi-do'], array_column($data, 'name'));
    }

    public function test_only_chosen_worker_claims_the_job(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer();
        [$chosen, $chosenToken] = Worker::register('chon', Worker::TYPE_IMAGE);
        [$otherWorker, $otherToken] = Worker::register('khac', Worker::TYPE_IMAGE);
        $chosen->users()->attach($buyer->id);
        $otherWorker->users()->attach($buyer->id);

        $id = $this->generate($buyer, $creative, extra: ['worker_id' => $chosen->id])->assertStatus(202)->json('data.id');
        $this->assertSame($chosen->id, Generation::query()->findOrFail($id)->worker_id);

        $this->claimJob($otherToken, allUsers: false)->assertNoContent();
        $this->claimJob($chosenToken, allUsers: false)->assertOk();
    }

    public function test_job_falls_back_to_other_worker_when_chosen_one_is_disabled(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer();
        [$chosen] = Worker::register('chon', Worker::TYPE_IMAGE);
        [$otherWorker, $otherToken] = Worker::register('khac', Worker::TYPE_IMAGE);
        $chosen->users()->attach($buyer->id);
        $otherWorker->users()->attach($buyer->id);
        $this->generate($buyer, $creative, extra: ['worker_id' => $chosen->id])->assertStatus(202);

        $chosen->update(['is_active' => false]);

        $this->claimJob($otherToken, allUsers: false)->assertOk();
    }

    public function test_rejects_worker_not_assigned_or_wrong_type(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer();
        [$publish] = Worker::register('dang-bai', Worker::TYPE_PUBLISH);
        $publish->users()->attach($buyer->id);

        $this->generate($buyer, $creative, extra: ['worker_id' => $publish->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.worker_id.0', 'Tài khoản chưa được gắn worker tạo ảnh, liên hệ A51 để được gắn.');

        [$image] = Worker::register('anh', Worker::TYPE_IMAGE);
        $image->users()->attach($buyer->id);
        [$foreign] = Worker::register('nguoi-khac', Worker::TYPE_IMAGE);

        $this->generate($buyer, $creative, extra: ['worker_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.worker_id.0', 'Worker tạo ảnh không còn được gắn cho tài khoản, chọn worker khác.');
        $this->assertSame(0, Generation::query()->count());
    }

    public function test_batch_saves_chosen_worker_on_every_generation(): void
    {
        $creative = $this->publishedCreative($this->creator()); // max_outputs = 5 -> 7 ảnh = 2 generation
        $buyer = $this->buyer();
        [$chosen] = Worker::register('chon', Worker::TYPE_IMAGE);
        $chosen->users()->attach($buyer->id);

        $this->actingAs($buyer)->post('/api/v1/image-batches', [
            'items' => [[
                'creative_id' => $creative->id,
                'quantity' => 7,
                'inputs' => ['product_image' => UploadedFile::fake()->image('product.png', 64, 64), 'aspectRatio' => '1:1'],
            ]],
            'idempotency_key' => 'batch-1',
            'worker_id' => $chosen->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame([$chosen->id, $chosen->id], Generation::query()->pluck('worker_id')->all());
    }

    public function test_retry_keeps_chosen_worker(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer();
        [$chosen, $token] = Worker::register('chon', Worker::TYPE_IMAGE);
        $chosen->users()->attach($buyer->id);
        $id = $this->generate($buyer, $creative, extra: ['worker_id' => $chosen->id])->json('data.id');
        $job = $this->claimJob($token, allUsers: false)->json('data.id');
        $this->withToken($token)->postJson("/api/v1/jobs/{$job}", ['status' => 'failed', 'error_code' => 'TIMEOUT'])->assertOk();

        $retryId = $this->actingAs($buyer)->postJson("/api/v1/generations/{$id}/retry", [
            'output_ids' => [$job],
            'idempotency_key' => 'retry-1',
        ])->assertStatus(202)->json('data.id');

        $this->assertSame($chosen->id, Generation::query()->findOrFail($retryId)->worker_id);
    }
}
