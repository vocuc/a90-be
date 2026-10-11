<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Enums\RevenueStatus;
use App\Enums\TransactionStatus;
use App\Models\CreatorRevenue;
use App\Models\CreatorWallet;
use App\Models\GenerationOutput;
use App\Models\Transaction;
use App\Services\SettingService;
use Tests\TestCase;

class RevenueAndSweepTest extends TestCase
{
    public function test_revenue_becomes_available_after_hold_period(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 1000);
        $this->generate($this->buyer(credit: 5000), $creative)->assertStatus(202);
        $this->runWorker([null]);

        $this->artisan('revenue:release')->assertSuccessful();
        $this->assertSame(RevenueStatus::Pending, CreatorRevenue::query()->firstOrFail()->status);

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->artisan('revenue:release')->assertSuccessful();
        $this->artisan('revenue:release')->assertSuccessful(); // chạy lại không cộng 2 lần

        $this->assertSame(RevenueStatus::Available, CreatorRevenue::query()->firstOrFail()->status);
        $wallet = CreatorWallet::query()->where('user_id', $creator->id)->firstOrFail();
        $this->assertSame(0, $wallet->pending_balance);
        $this->assertSame(100, $wallet->available_balance);
        $this->assertSame(100, $wallet->total_earned);
    }

    public function test_revenue_is_available_immediately_when_hold_days_is_zero(): void
    {
        app(SettingService::class)->set('revenue_hold_days', 0);
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 2000);
        $this->generate($this->buyer(credit: 5000), $creative)->assertStatus(202);
        $this->runWorker([null]);

        $revenue = CreatorRevenue::query()->firstOrFail();
        $this->assertSame(RevenueStatus::Available, $revenue->status);
        $this->assertNotNull($revenue->released_at);
        $wallet = CreatorWallet::query()->where('user_id', $creator->id)->firstOrFail();
        $this->assertSame(0, $wallet->pending_balance);
        $this->assertSame(200, $wallet->available_balance);

        $this->artisan('revenue:release')->assertSuccessful(); // không cộng thêm lần nữa
        $this->assertSame(200, $wallet->fresh()->available_balance);
    }

    public function test_images_no_worker_claims_are_failed_and_refunded(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);

        $this->generate($buyer, $creative, quantity: 2)->assertStatus(202);
        $this->assertSame(3000, $this->balance($buyer));

        $this->travel(29)->minutes();
        $this->artisan('generations:sweep-stuck')->assertSuccessful();
        $this->assertSame(2, GenerationOutput::query()->where('status', OutputStatus::Queued)->count());

        $this->travel(2)->minutes();
        $this->artisan('generations:sweep-stuck')->assertSuccessful();

        $this->assertSame(2, GenerationOutput::query()->where('status', OutputStatus::Failed)->where('error_code', 'TIMEOUT')->count());
        $this->assertSame(2, Transaction::query()->where('status', TransactionStatus::Refunded)->count());
        $this->assertSame(5000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
    }

    public function test_expired_lease_goes_back_to_queue_for_another_worker(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $this->generate($this->buyer(credit: 5000), $creative)->assertStatus(202);
        $dead = $this->workerToken('dead');
        $alive = $this->workerToken('alive');

        $jobId = $this->claimJob($dead)->json('data.id');

        // Còn trong 15 phút: chưa giao lại.
        $this->travel(14)->minutes();
        $this->artisan('generations:sweep-stuck')->assertSuccessful();
        $this->claimJob($alive)->assertNoContent();

        $this->travel(2)->minutes();
        $this->artisan('generations:sweep-stuck')->assertSuccessful();
        $output = GenerationOutput::query()->findOrFail($jobId);
        $this->assertSame(OutputStatus::Queued, $output->status);
        $this->assertNull($output->worker_id);

        $this->claimJob($alive)->assertOk()->assertJsonPath('data.id', $jobId);
        $this->assertSame(2, $output->fresh()->attempts);

        // Worker cũ gửi kết quả muộn: bị từ chối, worker mới vẫn hoàn thành bình thường.
        $this->completeJob($dead, $jobId)->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $this->completeJob($alive, $jobId)->assertOk();
        $this->assertSame(TransactionStatus::Completed, Transaction::query()->firstOrFail()->status);
    }

    public function test_image_fails_and_refunds_after_three_expired_leases(): void
    {
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);
        $this->generate($buyer, $creative)->assertStatus(202);
        $token = $this->workerToken();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $jobId = $this->claimJob($token)->assertOk()->json('data.id');
            $this->assertSame($attempt, GenerationOutput::query()->findOrFail($jobId)->attempts);
            $this->travel(16)->minutes();
            $this->artisan('generations:sweep-stuck')->assertSuccessful();
        }

        $output = GenerationOutput::query()->firstOrFail();
        $this->assertSame(OutputStatus::Failed, $output->status);
        $this->assertSame('TIMEOUT', $output->error_code);
        $this->assertSame(3, $output->attempts);
        $this->claimJob($token)->assertNoContent();
        $this->assertSame(5000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
    }
}
