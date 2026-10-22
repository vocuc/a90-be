<?php

namespace Tests;

use App\Enums\CreativeStatus;
use App\Enums\LedgerType;
use App\Models\AiCreative;
use App\Models\AiModel;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Generation;
use App\Models\User;
use App\Models\Worker;
use App\Services\CreditService;
use Database\Seeders\AiModelSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

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

    protected function creator(int $credit = 0): User
    {
        $user = $this->buyer($credit);
        $user->creatorProfile()->create(['shop_name' => 'Shop '.$user->id, 'username' => 'creator'.$user->id]);

        return $user;
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
            'is_public' => true,
            'published_at' => now(),
        ]);
        $creative->config()->create(array_merge([
            // 1 ảnh sản phẩm + tỉ lệ khung hình (bắt buộc với mọi Creative)
            'input_schema' => [
                ['key' => 'product_image', 'label' => 'Ảnh sản phẩm', 'type' => 'image', 'required' => true, 'sort_order' => 1, 'validation' => []],
                ['key' => 'aspectRatio', 'label' => 'Tỉ lệ khung hình', 'type' => 'aspect_ratio', 'required' => true, 'sort_order' => 2,
                    'validation' => [], 'options' => [['value' => '1:1', 'label' => '1:1']], 'default_value' => '1:1'],
            ],
            'prompt_template' => 'Place the product on a marble table. Avoid text and watermark.',
            'max_outputs' => 5,
            'default_aspect_ratio' => '1:1',
        ], $config));
        $creative->images()->create(['path' => 'creatives/sample.png']);

        return $creative;
    }

    /** Tạo ảnh với Creative của publishedCreative(): $inputs ghi đè inputs[...] (ảnh sản phẩm, aspectRatio). */
    protected function generate(User $user, AiCreative $creative, int $quantity = 1, array $extra = [], array $inputs = [])
    {
        return $this->actingAs($user)->post('/api/v1/generations', array_merge([
            'creative_id' => $creative->id,
            'inputs' => array_merge([
                'product_image' => UploadedFile::fake()->image('product.png', 64, 64),
                'aspectRatio' => '1:1',
            ], $inputs),
            'quantity' => $quantity,
            'idempotency_key' => (string) Str::uuid(),
        ], $extra), ['Accept' => 'application/json']);
    }

    /** Trạng thái mới nhất trong DB (response 202 chỉ phản ánh lúc vừa tạo, trước khi worker xử lý). */
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

    // ---- Worker ----

    /** Tạo worker, trả về token. */
    protected function workerToken(string $name = 'worker-1'): string
    {
        return Worker::register($name)[1];
    }

    /** Nhận job ảnh. Mặc định gắn mọi tài khoản hiện có vào worker trước (worker chỉ nhận job của tài khoản được gắn). */
    protected function claimJob(string $token, bool $allUsers = true): TestResponse
    {
        if ($allUsers) {
            Worker::findActiveByToken($token)?->imageUsers()->syncWithoutDetaching(User::query()->pluck('id'));
        }

        return $this->withToken($token)->getJson('/api/v1/jobs');
    }

    protected function completeJob(string $token, int $jobId): TestResponse
    {
        return $this->withToken($token)->post("/api/v1/jobs/{$jobId}", [
            'status' => 'completed',
            'image' => UploadedFile::fake()->image('result.png', 32, 32),
        ], ['Accept' => 'application/json']);
    }

    protected function failJob(string $token, int $jobId, string $errorCode = 'CONTENT_BLOCKED'): TestResponse
    {
        return $this->withToken($token)->postJson("/api/v1/jobs/{$jobId}", [
            'status' => 'failed',
            'error_code' => $errorCode,
            'error_message' => 'blocked by safety filter',
        ]);
    }

    /**
     * Worker nhận lần lượt các job đang chờ và trả kết quả: null = thành công, chuỗi = mã lỗi.
     *
     * @param  array<int, string|null>  $results
     */
    protected function runWorker(array $results): void
    {
        $token = $this->workerToken('runner-'.Str::random(6));

        foreach ($results as $errorCode) {
            $jobId = $this->claimJob($token)->assertOk()->json('data.id');
            $errorCode === null
                ? $this->completeJob($token, $jobId)->assertOk()
                : $this->failJob($token, $jobId, $errorCode)->assertOk();
        }
    }
}
