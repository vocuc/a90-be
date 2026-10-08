<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Enums\RevenueStatus;
use App\Enums\TransactionStatus;
use App\Models\CreatorRevenue;
use App\Models\CreatorWallet;
use App\Models\GenerationOutput;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RevenueAndSweepTest extends TestCase
{
    public function test_revenue_becomes_available_after_hold_period(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 1000);
        $this->fakeGemini([Http::response(self::geminiImage())]);
        $this->generate($this->buyer(credit: 5000), $creative)->assertStatus(202);

        $this->artisan('revenue:release')->assertSuccessful();
        $this->assertSame(RevenueStatus::Pending, CreatorRevenue::query()->firstOrFail()->status);

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->artisan('revenue:release')->assertSuccessful();
        $this->artisan('revenue:release')->assertSuccessful(); // chạy lại không cộng 2 lần

        $this->assertSame(RevenueStatus::Available, CreatorRevenue::query()->firstOrFail()->status);
        $wallet = CreatorWallet::query()->where('user_id', $creator->id)->firstOrFail();
        $this->assertSame(0, $wallet->pending_balance);
        $this->assertSame(900, $wallet->available_balance);
        $this->assertSame(900, $wallet->total_earned);
    }

    public function test_stuck_outputs_are_failed_and_refunded(): void
    {
        Queue::fake(); // worker "chết": job không bao giờ chạy
        $creative = $this->publishedCreative($this->creator(), price: 1000);
        $buyer = $this->buyer(credit: 5000);

        $this->generate($buyer, $creative, quantity: 2)->assertStatus(202);
        $this->assertSame(3000, $this->balance($buyer));

        $this->travel(31)->minutes();
        $this->artisan('generations:sweep-stuck')->assertSuccessful();

        $this->assertSame(2, GenerationOutput::query()->where('status', OutputStatus::Failed)->where('error_code', 'TIMEOUT')->count());
        $this->assertSame(2, Transaction::query()->where('status', TransactionStatus::Refunded)->count());
        $this->assertSame(5000, $this->balance($buyer));
        $this->assertLedgerMatchesBalance($buyer);
    }
}
