<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Exceptions\BusinessException;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Nơi DUY NHẤT được thay đổi số dư Credit.
 * Mọi hàm đều yêu cầu đang ở trong DB transaction; ví được khóa FOR UPDATE.
 */
class CreditService
{
    public function lockWallet(int $userId): CreditWallet
    {
        $this->assertInTransaction();

        CreditWallet::query()->firstOrCreate(['user_id' => $userId]);

        return CreditWallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    public function assertSufficient(CreditWallet $wallet, int $required): void
    {
        if ($wallet->balance < $required) {
            throw BusinessException::insufficientCredit($required, $wallet->balance);
        }
    }

    /**
     * Ghi một biến động Credit. amount > 0 là cộng, amount < 0 là trừ.
     * Trả về null nếu idempotency_key đã được ghi trước đó (không ghi lần 2).
     */
    public function apply(
        CreditWallet $wallet,
        int $amount,
        LedgerType $type,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
        ?int $createdBy = null,
    ): ?CreditLedger {
        $this->assertInTransaction();

        if ($amount === 0) {
            return null;
        }

        if (CreditLedger::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return null;
        }

        $newBalance = $wallet->balance + $amount;
        if ($newBalance < 0) {
            throw BusinessException::insufficientCredit(-$amount, $wallet->balance);
        }

        $wallet->balance = $newBalance;
        $wallet->save();

        return CreditLedger::query()->create([
            'user_id' => $wallet->user_id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $newBalance,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'note' => $note,
            'created_by' => $createdBy,
        ]);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Thay đổi Credit phải nằm trong DB transaction.');
        }
    }
}
