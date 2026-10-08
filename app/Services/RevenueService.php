<?php

namespace App\Services;

use App\Enums\RevenueStatus;
use App\Models\CreatorRevenue;
use App\Models\CreatorWallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class RevenueService
{
    public function __construct(
        private SettingService $settings,
        private AuditLogger $audit,
    ) {}

    /** Ghi doanh thu pending cho Transaction vừa hoàn tất. Gọi trong DB transaction. */
    public function record(Transaction $transaction): ?CreatorRevenue
    {
        if ($transaction->creator_amount <= 0) {
            return null;
        }

        $revenue = CreatorRevenue::query()->firstOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'creator_id' => $transaction->creator_id,
                'amount' => $transaction->creator_amount,
                'status' => RevenueStatus::Pending,
                'available_at' => now()->addDays($this->settings->int('revenue_hold_days')),
            ],
        );

        if ($revenue->wasRecentlyCreated) {
            $wallet = $this->lockWallet($transaction->creator_id);
            $wallet->increment('pending_balance', $revenue->amount);
            $wallet->increment('total_earned', $revenue->amount);
        }

        return $revenue;
    }

    /** Chuyển các khoản doanh thu đã hết thời gian giữ sang available. Trả về số khoản đã xử lý. */
    public function releaseDue(): int
    {
        $released = 0;

        CreatorRevenue::query()
            ->where('status', RevenueStatus::Pending)
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($revenues) use (&$released) {
                foreach ($revenues as $revenue) {
                    if ($this->release($revenue->id)) {
                        $released++;
                    }
                }
            });

        return $released;
    }

    private function release(int $revenueId): bool
    {
        return DB::transaction(function () use ($revenueId) {
            $revenue = CreatorRevenue::query()->lockForUpdate()->find($revenueId);
            if (! $revenue || $revenue->status !== RevenueStatus::Pending) {
                return false;
            }

            $wallet = $this->lockWallet($revenue->creator_id);
            $wallet->decrement('pending_balance', $revenue->amount);
            $wallet->increment('available_balance', $revenue->amount);

            $revenue->update(['status' => RevenueStatus::Available, 'released_at' => now()]);
            $this->audit->log('revenue.release', $revenue, ['amount' => $revenue->amount], $revenue->creator_id);

            return true;
        });
    }

    private function lockWallet(int $creatorId): CreatorWallet
    {
        CreatorWallet::query()->firstOrCreate(['user_id' => $creatorId]);

        return CreatorWallet::query()->where('user_id', $creatorId)->lockForUpdate()->firstOrFail();
    }
}
