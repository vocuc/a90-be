<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\OutputStatus;
use App\Enums\RevenueStatus;
use App\Enums\TransactionStatus;
use App\Models\CreatorRevenue;
use App\Models\CreatorWallet;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Transaction;
use App\Models\Worker;
use App\Services\GenerationFinalizer;
use App\Services\MediaStorage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    public function test_generation_waits_in_db_until_a_worker_claims_it(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);

        $response = $this->generate($buyer, $creative, quantity: 2)->assertStatus(202);

        // Chưa có worker nào nhận: mọi ảnh vẫn chờ, Credit đã bị giữ.
        $this->assertSame(['status' => 'queued', 'outputs' => [
            ['status' => 'queued', 'error_code' => null],
            ['status' => 'queued', 'error_code' => null],
        ]], $this->latestStatus($response));
        $this->assertSame(3000, $this->balance($buyer));
    }

    public function test_worker_receives_prompt_input_image_and_aspect_ratio(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $response = $this->generate($this->buyer(credit: 5000), $creative, inputs: ['aspectRatio' => '4:5'])->assertStatus(202);
        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));
        $token = $this->workerToken();

        $job = $this->claimJob($token)->assertOk()->json('data');

        $this->assertSame($generation->outputs[0]->id, $job['id']);
        $this->assertSame(['id', 'request'], array_keys($job));
        $this->assertSame([
            'productImage' => "https://media.test/{$generation->input_paths['product_image']}",
            'prompt' => 'Place the product on a marble table. Avoid text and watermark.',
            'aspectRatio' => '4:5',
            'modelDisplayName' => 'Gemini 2.5 Flash Image',
        ], $job['request']);

        $output = $generation->outputs[0]->fresh();
        $this->assertSame(OutputStatus::Processing, $output->status);
        $this->assertSame(Worker::query()->value('id'), $output->worker_id);
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);
        $this->assertNotNull(Worker::query()->value('last_seen_at'));
    }

    public function test_no_pending_job_returns_204(): void
    {
        $this->claimJob($this->workerToken())->assertNoContent();
    }

    public function test_invalid_or_disabled_worker_token_is_rejected(): void
    {
        $this->claimJob('wrong-token')->assertStatus(401)->assertJsonPath('code', 'WORKER_UNAUTHORIZED');
        $this->withToken('wrong-token')->postJson('/api/v1/jobs/999', ['status' => 'failed'])->assertStatus(401);
        $this->getJson('/api/v1/jobs')->assertStatus(401);

        $token = $this->workerToken();
        Worker::query()->update(['is_active' => false]);
        $this->claimJob($token)->assertStatus(401);
    }

    public function test_each_claim_gets_a_different_image(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $this->generate($this->buyer(credit: 5000), $creative, quantity: 2)->assertStatus(202);
        $first = $this->workerToken('w1');
        $second = $this->workerToken('w2');

        $a = $this->claimJob($first)->assertOk()->json('data.id');
        $b = $this->claimJob($second)->assertOk()->json('data.id');

        $this->assertNotSame($a, $b);
        $this->claimJob($first)->assertNoContent();
    }

    public function test_paid_generation_charges_per_image_and_records_revenue(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 1000);
        $buyer = $this->buyer(credit: 10000);

        $response = $this->generate($buyer, $creative, quantity: 3)->assertStatus(202);
        $this->runWorker([null, null, null]);

        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));
        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertSame(3, $generation->success_count);

        // Ảnh worker gửi lên được lưu và trả link cho người dùng.
        $output = $generation->outputs[0];
        $this->assertStringStartsWith('generations/outputs/', $output->image_path);
        $this->assertSame('image/png', $output->mime);
        $this->assertTrue(app(MediaStorage::class)->disk()->exists($output->image_path));
        $this->actingAs($buyer)->getJson("/api/v1/generations/{$generation->id}/status")
            ->assertJsonPath('data.outputs.0.image_url', "https://media.test/{$output->image_path}");

        // Mỗi ảnh một Transaction, phí 10%: Creator nhận 900 / ảnh.
        $transactions = Transaction::query()->where('generation_id', $generation->id)->get();
        $this->assertCount(3, $transactions);
        $this->assertTrue($transactions->every(fn ($tx) => $tx->status === TransactionStatus::Completed
            && $tx->platform_fee_amount === 100 && $tx->creator_amount === 900));

        $this->assertSame(7000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);

        $wallet = CreatorWallet::query()->where('user_id', $creator->id)->firstOrFail();
        $this->assertSame(2700, $wallet->pending_balance);
        $this->assertSame(0, $wallet->available_balance);
        $this->assertSame(3, $creative->fresh()->usage_count);
    }

    public function test_insufficient_credit_creates_nothing(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 1500);

        $this->generate($buyer, $creative, quantity: 2)
            ->assertStatus(402)
            ->assertJsonPath('code', 'INSUFFICIENT_CREDIT');

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1500, $this->balance($buyer));
    }

    public function test_same_idempotency_key_does_not_charge_twice(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);
        $key = (string) Str::uuid();

        $first = $this->generate($buyer, $creative, extra: ['idempotency_key' => $key])->assertStatus(202);
        $second = $this->generate($buyer, $creative, extra: ['idempotency_key' => $key])->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, GenerationOutput::query()->count());
        $this->assertSame(4000, $this->balance($buyer));
    }

    public function test_partial_failure_refunds_only_failed_images(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 10000);

        $response = $this->generate($buyer, $creative, quantity: 4)->assertStatus(202);
        $this->runWorker([null, 'CONTENT_BLOCKED', null, null]);
        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));

        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertSame(3, $generation->success_count);
        $this->assertSame(1, $generation->failed_count);
        $this->assertSame('CONTENT_BLOCKED', $generation->outputs[1]->error_code);
        $this->assertSame('blocked by safety filter', $generation->outputs[1]->error_message);

        $this->assertSame(1, Transaction::query()->where('status', TransactionStatus::Refunded)->count());
        $this->assertSame(7000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
        $this->assertSame(3, CreatorRevenue::query()->where('status', RevenueStatus::Pending)->count());
        $this->assertSame(3, $creative->fresh()->usage_count);

        // Người dùng thấy thông báo thân thiện, không thấy lỗi nội bộ của worker.
        $this->actingAs($buyer)->getJson("/api/v1/generations/{$generation->id}")
            ->assertJsonPath('data.outputs.1.error_message', 'Ảnh hoặc nội dung vi phạm chính sách an toàn của AI.');
    }

    public function test_finalizing_an_output_twice_refunds_once(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);
        $this->generate($buyer, $creative)->assertStatus(202);
        $token = $this->workerToken();

        $jobId = $this->claimJob($token)->json('data.id');
        $this->failJob($token, $jobId)->assertOk();
        $this->assertSame(5000, $this->balance($buyer));

        // Worker gửi lại hoặc lệnh sweep chạm vào output đã chốt: không hoàn thêm.
        $this->failJob($token, $jobId)->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $this->completeJob($token, $jobId)->assertStatus(409);
        app(GenerationFinalizer::class)->fail($jobId, 'TIMEOUT', 'again');

        $this->assertSame(5000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
        $this->assertSame(OutputStatus::Failed, GenerationOutput::query()->findOrFail($jobId)->status);
    }

    public function test_worker_cannot_submit_a_job_held_by_another_worker(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $this->generate($this->buyer(credit: 5000), $creative)->assertStatus(202);
        $owner = $this->workerToken('w1');
        $other = $this->workerToken('w2');

        $jobId = $this->claimJob($owner)->json('data.id');

        $this->completeJob($other, $jobId)->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $this->assertSame(OutputStatus::Processing, GenerationOutput::query()->findOrFail($jobId)->status);
        $this->assertCount(0, app(MediaStorage::class)->disk()->allFiles('generations/outputs'));

        $this->completeJob($owner, $jobId)->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_result_payload_is_validated(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $this->generate($this->buyer(credit: 5000), $creative)->assertStatus(202);
        $token = $this->workerToken();
        $jobId = $this->claimJob($token)->json('data.id');

        $this->withToken($token)->postJson("/api/v1/jobs/{$jobId}", ['status' => 'completed'])
            ->assertStatus(422)->assertJsonValidationErrors('image');
        $this->withToken($token)->postJson("/api/v1/jobs/{$jobId}", ['status' => 'failed'])
            ->assertStatus(422)->assertJsonValidationErrors('error_code');
        $this->withToken($token)->postJson("/api/v1/jobs/{$jobId}", ['status' => 'done'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->withToken($token)->postJson('/api/v1/jobs/999999', ['status' => 'failed', 'error_code' => 'X'])
            ->assertNotFound();

        $this->assertSame(OutputStatus::Processing, GenerationOutput::query()->findOrFail($jobId)->status);
    }

    public function test_creator_using_own_creative_is_not_charged(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 1000);

        $response = $this->generate($creator, $creative)->assertStatus(202);
        $this->runWorker([null]);

        $this->assertSame('owner', $response->json('data.type'));
        $this->assertSame('completed', $this->latestStatus($response)['status']);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, CreatorRevenue::query()->count());
        $this->assertSame(0, $creative->fresh()->usage_count);
    }

    public function test_client_supplied_price_model_and_prompt_are_ignored(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);

        $this->generate($buyer, $creative, extra: [
            'price' => 1, 'price_per_image' => 1, 'model_id' => 999, 'prompt' => 'hack', 'platform_fee' => 0,
        ])->assertStatus(202);

        $this->assertSame(4000, $this->balance($buyer));
        $job = $this->claimJob($this->workerToken())->json('data');
        $this->assertSame('Gemini 2.5 Flash Image', $job['request']['modelDisplayName']);
        $this->assertStringNotContainsString('hack', $job['request']['prompt']);
    }

    public function test_quantity_and_aspect_ratio_limits(): void
    {
        $creative = $this->publishedCreative($this->creator(), config: ['max_outputs' => 2]);
        $buyer = $this->buyer(credit: 50000);

        $this->generate($buyer, $creative, quantity: 3)->assertStatus(422)->assertJsonPath('code', 'QUANTITY_EXCEEDED');
        // Chỉ nhận tỉ lệ model hỗ trợ
        $this->generate($buyer, $creative, inputs: ['aspectRatio' => '7:3'])->assertStatus(422)->assertJsonValidationErrors('inputs.aspectRatio');
        $this->assertSame(50000, $this->balance($buyer));
    }

    public function test_retry_selected_outputs_charges_again_with_new_transactions(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 10000);

        $original = $this->generate($buyer, $creative, quantity: 2)->json('data');
        $this->runWorker([null, 'CONTENT_BLOCKED']);
        $failedOutputId = $original['outputs'][1]['id'];
        $this->assertSame(9000, $this->balance($buyer));

        $retry = $this->actingAs($buyer)->postJson("/api/v1/generations/{$original['id']}/retry", [
            'output_ids' => [$failedOutputId],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);

        $this->assertSame($original['id'], $retry->json('data.parent_generation_id'));
        $this->assertSame($failedOutputId, $retry->json('data.outputs.0.retry_of_output_id'));

        // Lần retry dùng lại ảnh đầu vào của lần tạo gốc.
        $token = $this->workerToken('retry-worker');
        $job = $this->claimJob($token)->json('data');
        $this->assertSame($retry->json('data.outputs.0.id'), $job['id']);
        $this->assertSame($original['input_images']['product_image'], $job['request']['productImage']);
        $this->completeJob($token, $job['id'])->assertOk();

        $this->assertSame('completed', $this->latestStatus($retry)['status']);
        $this->assertSame(3, Transaction::query()->count());
        $this->assertSame(8000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
    }

    public function test_user_cannot_see_others_generations(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer(credit: 5000);
        $id = $this->generate($buyer, $creative)->json('data.id');
        $this->runWorker([null]);

        $this->actingAs($this->buyer())->getJson("/api/v1/generations/{$id}")->assertNotFound();
        $this->actingAs($buyer)->getJson("/api/v1/generations/{$id}/status")
            ->assertOk()
            ->assertJsonPath('data.outputs.0.status', OutputStatus::Completed->value);
    }
}
