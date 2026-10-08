<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreditLedgerResource;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function __construct(private CreditService $credits) {}

    /** Admin cộng Credit bằng tay (chưa có cổng thanh toán). */
    public function topup(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        [$entry, $created] = $this->credits->adminAdjust(
            $user->id, $data['amount'], $data['idempotency_key'], $data['note'] ?? null, $request->user()->id,
        );

        return response()->json([
            'data' => [
                'ledger' => new CreditLedgerResource($entry),
                'balance' => (int) $user->creditWallet()->value('balance'),
            ],
        ], $created ? 201 : 200);
    }
}
