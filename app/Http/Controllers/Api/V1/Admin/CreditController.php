<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\LedgerType;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CreditLedgerResource;
use App\Models\CreditLedger;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditController extends Controller
{
    public function __construct(
        private CreditService $credits,
        private AuditLogger $audit,
    ) {}

    /** Admin cộng Credit bằng tay (chưa có cổng thanh toán). */
    public function topup(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $key = "topup:{$data['idempotency_key']}";

        $entry = DB::transaction(function () use ($request, $user, $data, $key) {
            $wallet = $this->credits->lockWallet($user->id);
            $entry = $this->credits->apply(
                $wallet, $data['amount'], LedgerType::AdminTopup, $key, 'user', $user->id, $data['note'] ?? null, $request->user()->id,
            );

            if ($entry) {
                $this->audit->log('credit.admin_topup', $user, ['amount' => $data['amount'], 'note' => $data['note'] ?? null]);
            }

            return $entry;
        });

        // Gửi lại cùng idempotency_key: trả về bản ghi cũ, không cộng lần 2.
        $entry ??= CreditLedger::query()->where('idempotency_key', $key)->firstOrFail();
        if ($entry->user_id !== $user->id || $entry->amount !== $data['amount']) {
            throw BusinessException::idempotencyConflict();
        }

        return response()->json([
            'data' => [
                'ledger' => new CreditLedgerResource($entry),
                'balance' => (int) $user->creditWallet()->value('balance'),
            ],
        ], $entry->wasRecentlyCreated ? 201 : 200);
    }
}
