<?php

namespace Tests;

use App\Enums\ApiKeyStatus;
use App\Enums\CreativeStatus;
use App\Enums\LedgerType;
use App\Models\AiCreative;
use App\Models\AiModel;
use App\Models\CreatorApiKey;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditService;
use Database\Seeders\AiModelSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SettingSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([SettingSeeder::class, AiModelSeeder::class, CategorySeeder::class]);

        Storage::fake('media');
        Storage::disk('media')->buildTemporaryUrlsUsing(fn ($path) => "https://media.test/{$path}");
        Http::preventStrayRequests();
    }

    protected function buyer(int $credit = 0): User
    {
        $user = User::factory()->create();
        CreditWallet::query()->create(['user_id' => $user->id]);

        if ($credit > 0) {
            DB::transaction(function () use ($user, $credit) {
                $credits = app(CreditService::class);
                $credits->apply($credits->lockWallet($user->id), $credit, LedgerType::AdminTopup, 'test:'.Str::uuid());
            });
        }

        return $user;
    }

    protected function creator(int $keys = 1): User
    {
        $user = $this->buyer();
        $user->creatorProfile()->create(['shop_name' => 'Shop '.$user->id, 'username' => 'creator'.$user->id]);

        for ($i = 1; $i <= $keys; $i++) {
            $this->addKey($user, "AIzaKEY{$i}-{$user->id}");
        }

        return $user;
    }

    protected function addKey(User $creator, string $key): CreatorApiKey
    {
        return $creator->apiKeys()->create([
            'provider_id' => AiModel::query()->firstOrFail()->provider_id,
            'label' => 'key',
            'api_key' => $key,
            'key_hint' => substr($key, -4),
            'status' => ApiKeyStatus::Active,
        ]);
    }

    protected function publishedCreative(User $creator, int $price = 1000, array $config = []): AiCreative
    {
        $creative = AiCreative::query()->create([
            'creator_id' => $creator->id,
            'model_id' => AiModel::query()->firstOrFail()->id,
            'title' => 'Ảnh sản phẩm studio',
            'slug' => 'creative-'.Str::random(8),
            'price_per_image' => $price,
            'status' => CreativeStatus::Published,
            'published_at' => now(),
        ]);
        $creative->config()->create(array_merge([
            'system_prompt' => 'You are a product photographer.',
            'creative_prompt' => [['label' => 'Bối cảnh', 'value' => 'Place the product on a marble table.']],
            'negative_prompt' => 'text, watermark',
            'max_outputs' => 5,
            'default_aspect_ratio' => '1:1',
        ], $config));
        $creative->images()->create(['path' => 'creatives/sample.png']);

        return $creative;
    }

    protected function generate(User $user, AiCreative $creative, int $quantity = 1, array $extra = [])
    {
        return $this->actingAs($user)->post('/api/v1/generations', array_merge([
            'creative_id' => $creative->id,
            'input_image' => UploadedFile::fake()->image('product.png', 64, 64),
            'quantity' => $quantity,
            'aspect_ratio' => '1:1',
            'idempotency_key' => (string) Str::uuid(),
        ], $extra), ['Accept' => 'application/json']);
    }

    /** Trạng thái mới nhất trong DB (response 202 chỉ phản ánh lúc vừa đưa vào queue). */
    protected function latestStatus($response): array
    {
        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));

        return [
            'status' => $generation->status->value,
            'outputs' => $generation->outputs->map(fn ($o) => ['status' => $o->status->value, 'error_code' => $o->error_code])->all(),
        ];
    }

    protected function balance(User $user): int
    {
        return (int) CreditWallet::query()->where('user_id', $user->id)->value('balance');
    }

    protected function assertLedgerMatchesBalance(User $user): void
    {
        $this->assertSame(
            $this->balance($user),
            (int) CreditLedger::query()->where('user_id', $user->id)->sum('amount'),
            'Tổng credit_ledger phải bằng số dư ví.'
        );
    }

    // ---- Gemini fake responses ----

    protected static function geminiImage(): array
    {
        return ['candidates' => [[
            'content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNG-BYTES')]]]],
            'finishReason' => 'STOP',
        ]]];
    }

    protected static function geminiBlocked(): array
    {
        return ['promptFeedback' => ['blockReason' => 'SAFETY']];
    }

    protected static function geminiInvalidKey(): array
    {
        return ['error' => [
            'code' => 400,
            'message' => 'API key not valid. Please pass a valid API key.',
            'status' => 'INVALID_ARGUMENT',
            'details' => [['reason' => 'API_KEY_INVALID']],
        ]];
    }

    /** @param  array<int, PromiseInterface>  $responses */
    protected function fakeGemini(array $responses): HttpFactory
    {
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->pushResponse($response);
        }

        return Http::fake(['*:generateContent' => $sequence]);
    }
}
