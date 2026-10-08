<?php

namespace Tests\Feature;

use App\Enums\CreativePauseReason;
use App\Enums\CreativeStatus;
use App\Enums\GenerationStatus;
use App\Enums\OutputStatus;
use App\Enums\RevenueStatus;
use App\Enums\TransactionStatus;
use App\Jobs\ProcessGenerationOutput;
use App\Models\CreatorRevenue;
use App\Models\CreatorWallet;
use App\Models\Generation;
use App\Models\Transaction;
use App\Services\AI\ApiKeyRotator;
use App\Services\AI\ModelService;
use App\Services\CreativeService;
use App\Services\GenerationFinalizer;
use App\Services\MediaStorage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    public function test_paid_generation_charges_per_image_and_records_revenue(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 1000);
        $buyer = $this->buyer(credit: 10000);
        $this->fakeGemini([
            Http::response(self::geminiImage()),
            Http::response(self::geminiImage()),
            Http::response(self::geminiImage()),
        ]);

        $response = $this->generate($buyer, $creative, quantity: 3)->assertStatus(202);

        $generation = Generation::query()->findOrFail($response->json('data.id'));
        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertSame(3, $generation->success_count);

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

        // Prompt gộp và key của Creator được gửi sang Gemini.
        Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', "AIzaKEY1-{$creator->id}")
            && str_contains($request['contents'][0]['parts'][0]['text'], "Place the product on a marble table.\n\nAvoid: text, watermark")
            && $request['generationConfig']['imageConfig']['aspectRatio'] === '1:1');
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
        Http::assertNothingSent();
    }

    public function test_same_idempotency_key_does_not_charge_twice(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);
        $this->fakeGemini([Http::response(self::geminiImage())]);
        $key = (string) Str::uuid();

        $first = $this->generate($buyer, $creative, extra: ['idempotency_key' => $key])->assertStatus(202);
        $second = $this->generate($buyer, $creative, extra: ['idempotency_key' => $key])->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(4000, $this->balance($buyer));
    }

    public function test_partial_failure_refunds_only_failed_images(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 1000);
        $buyer = $this->buyer(credit: 10000);
        $this->fakeGemini([
            Http::response(self::geminiImage()),
            Http::response(self::geminiBlocked()),
            Http::response(self::geminiImage()),
            Http::response(self::geminiImage()),
        ]);

        $response = $this->generate($buyer, $creative, quantity: 4)->assertStatus(202);
        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));

        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertSame(3, $generation->success_count);
        $this->assertSame(1, $generation->failed_count);
        $this->assertSame('CONTENT_BLOCKED', $generation->outputs[1]->error_code);

        $this->assertSame(1, Transaction::query()->where('status', TransactionStatus::Refunded)->count());
        $this->assertSame(7000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
        $this->assertSame(3, CreatorRevenue::query()->where('status', RevenueStatus::Pending)->count());
        $this->assertSame(3, $creative->fresh()->usage_count);
    }

    public function test_finalizing_an_output_twice_refunds_once(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);
        $this->fakeGemini([Http::response(self::geminiBlocked())]);

        $response = $this->generate($buyer, $creative)->assertStatus(202);
        $output = Generation::query()->findOrFail($response->json('data.id'))->outputs()->firstOrFail();
        $this->assertSame(5000, $this->balance($buyer));

        // Job chạy lại hoặc lệnh sweep chạm vào output đã chốt: không hoàn thêm.
        app(GenerationFinalizer::class)->fail($output->id, 'TIMEOUT', 'again');
        (new ProcessGenerationOutput($output->id))->handle(
            app(GenerationFinalizer::class),
            app(ApiKeyRotator::class),
            app(ModelService::class),
            app(MediaStorage::class),
            app(CreativeService::class),
        );

        $this->assertSame(5000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
    }

    public function test_invalid_key_rotates_to_next_key(): void
    {
        $creator = $this->creator(keys: 2);
        $creative = $this->publishedCreative($creator);
        $buyer = $this->buyer(credit: 5000);
        $this->fakeGemini([
            Http::response(self::geminiInvalidKey(), 400),
            Http::response(self::geminiImage()),
        ]);

        $response = $this->generate($buyer, $creative)->assertStatus(202);

        $this->assertSame('completed', $this->latestStatus($response)['status']);
        $this->assertSame(1, $creator->apiKeys()->where('status', 'invalid')->count());
        $this->assertSame(CreativeStatus::Published, $creative->fresh()->status);
    }

    public function test_all_keys_invalid_refunds_and_auto_pauses_creative(): void
    {
        $creator = $this->creator(keys: 2);
        $creative = $this->publishedCreative($creator);
        $buyer = $this->buyer(credit: 5000);
        $this->fakeGemini([
            Http::response(self::geminiInvalidKey(), 400),
            Http::response(self::geminiInvalidKey(), 400),
        ]);

        $response = $this->generate($buyer, $creative)->assertStatus(202);

        $this->assertSame('failed', $this->latestStatus($response)['status']);
        $this->assertSame('NO_USABLE_API_KEY', $this->latestStatus($response)['outputs'][0]['error_code']);
        $this->assertSame(5000, $this->balance($buyer));
        $creative->refresh();
        $this->assertSame(CreativeStatus::Paused, $creative->status);
        $this->assertSame(CreativePauseReason::NoValidApiKey, $creative->pause_reason);

        // Creative đã bị pause: không cho tạo nữa.
        $this->generate($buyer, $creative)->assertStatus(404)->assertJsonPath('code', 'CREATIVE_NOT_AVAILABLE');
    }

    public function test_rate_limited_key_is_cooled_down_not_invalidated(): void
    {
        $creator = $this->creator(keys: 1);
        $creative = $this->publishedCreative($creator);
        $buyer = $this->buyer(credit: 5000);
        $this->fakeGemini([Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']], 429)]);

        $response = $this->generate($buyer, $creative)->assertStatus(202);
        $this->assertSame('failed', $this->latestStatus($response)['status']);

        $key = $creator->apiKeys()->firstOrFail();
        $this->assertSame('active', $key->status->value);
        $this->assertNotNull($key->cooldown_until);
        $this->assertSame(CreativeStatus::Published, $creative->fresh()->status);
        $this->assertSame(5000, $this->balance($buyer));
    }

    public function test_creator_using_own_creative_is_not_charged(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 1000);
        $this->fakeGemini([Http::response(self::geminiImage())]);

        $response = $this->generate($creator, $creative)->assertStatus(202);

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
        $this->fakeGemini([Http::response(self::geminiImage())]);

        $this->generate($buyer, $creative, extra: [
            'price' => 1, 'price_per_image' => 1, 'model_id' => 999, 'prompt' => 'hack', 'platform_fee' => 0,
        ])->assertStatus(202);

        $this->assertSame(4000, $this->balance($buyer));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'gemini-2.5-flash-image')
            && ! str_contains($request['contents'][0]['parts'][0]['text'], 'hack'));
    }

    public function test_quantity_and_aspect_ratio_limits(): void
    {
        $creative = $this->publishedCreative($this->creator(), config: ['max_outputs' => 2, 'allowed_aspect_ratios' => ['1:1', '3:4']]);
        $buyer = $this->buyer(credit: 50000);

        $this->generate($buyer, $creative, quantity: 3)->assertStatus(422)->assertJsonPath('code', 'QUANTITY_EXCEEDED');
        $this->generate($buyer, $creative, extra: ['aspect_ratio' => '16:9'])->assertStatus(422)->assertJsonPath('code', 'ASPECT_RATIO_NOT_ALLOWED');
        $this->assertSame(50000, $this->balance($buyer));
    }

    public function test_retry_selected_outputs_charges_again_with_new_transactions(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 10000);
        $this->fakeGemini([
            Http::response(self::geminiImage()),
            Http::response(self::geminiBlocked()),
            Http::response(self::geminiImage()),
        ]);

        $original = $this->generate($buyer, $creative, quantity: 2)->json('data');
        $failedOutputId = $original['outputs'][1]['id'];
        $this->assertSame(9000, $this->balance($buyer));

        $retry = $this->actingAs($buyer)->postJson("/api/v1/generations/{$original['id']}/retry", [
            'output_ids' => [$failedOutputId],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);

        $this->assertSame($original['id'], $retry->json('data.parent_generation_id'));
        $this->assertSame($failedOutputId, $retry->json('data.outputs.0.retry_of_output_id'));
        $this->assertSame('completed', $this->latestStatus($retry)['status']);
        $this->assertSame(3, Transaction::query()->count());
        $this->assertSame(8000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
    }

    public function test_user_cannot_see_others_generations(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer(credit: 5000);
        $this->fakeGemini([Http::response(self::geminiImage())]);
        $id = $this->generate($buyer, $creative)->json('data.id');

        $this->actingAs($this->buyer())->getJson("/api/v1/generations/{$id}")->assertNotFound();
        $this->actingAs($buyer)->getJson("/api/v1/generations/{$id}/status")
            ->assertOk()
            ->assertJsonPath('data.outputs.0.status', OutputStatus::Completed->value);
    }
}
