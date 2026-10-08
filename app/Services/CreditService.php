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
 * Các hàm cấp thấp (lockWallet, apply) yêu cầu đang ở trong DB transaction; ví được khóa FOR UPDATE.
 */
class CreditService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Admin cộng (amount > 0) hoặc trừ (amount < 0, loại admin_adjust) Credit bằng tay.
     * Gửi lại cùng idempotency_key trả về bản ghi cũ, không ghi lần 2.
     *
     * @return array{0: CreditLedger, 1: bool} bản ghi ledger và cờ "vừa tạo mới"
     */
    public function adminAdjust(int $userId, int $amount, string $idempotencyKey, ?string $note, int $adminId): array
    {
        $key = "admin:{$idempotencyKey}";
        $type = $amount > 0 ? LedgerType::AdminTopup : LedgerType::AdminAdjust;

        $entry = DB::transaction(function () use ($userId, $amount, $key, $type, $note, $adminId) {
            $entry = $this->apply($this->lockWallet($userId), $amount, $type, $key, 'user', $userId, $note, $adminId);

            if ($entry) {
                $this->audit->log('credit.'.$type->value, $entry, ['user_id' => $userId, 'amount' => $amount, 'note' => $note], $adminId);
            }

            return $entry;
        });

        if ($entry) {
            return [$entry, true];
        }

        $existing = CreditLedger::query()->where('idempotency_key', $key)->first();
        if (! $existing || $existing->user_id !== $userId || $existing->amount !== $amount) {
            throw BusinessException::idempotencyConflict();
        }

        return [$existing, false];
    }

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
