<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreditLedgerResource;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreditWalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $balance = CreditWallet::query()->where('user_id', $request->user()->id)->value('balance') ?? 0;

        return response()->json(['data' => ['balance' => (int) $balance]]);
    }

    public function ledger(Request $request): AnonymousResourceCollection
    {
        $entries = CreditLedger::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return CreditLedgerResource::collection($entries);
    }
}
