<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Enums\RevenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorRevenueResource;
use App\Models\AiCreative;
use App\Models\CreatorRevenue;
use App\Models\CreatorWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $revenue = fn ($from) => (int) CreatorRevenue::query()
            ->where('creator_id', $userId)
            ->where('status', '!=', RevenueStatus::Reversed)
            ->where('created_at', '>=', $from)
            ->sum('amount');

        $creatives = AiCreative::query()->where('creator_id', $userId);

        return response()->json(['data' => [
            'creatives_by_status' => (clone $creatives)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'total_usage' => (int) (clone $creatives)->sum('usage_count'),
            'revenue_today' => $revenue(now()->startOfDay()),
            'revenue_this_month' => $revenue(now()->startOfMonth()),
            'wallet' => $this->walletData($userId),
        ]]);
    }

    public function wallet(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->walletData($request->user()->id)]);
    }

    public function walletTransactions(Request $request): AnonymousResourceCollection
    {
        $revenues = CreatorRevenue::query()
            ->where('creator_id', $request->user()->id)
            ->with('transaction.creative')
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return CreatorRevenueResource::collection($revenues);
    }

    private function walletData(int $userId): array
    {
        $wallet = CreatorWallet::query()->firstOrCreate(['user_id' => $userId]);

        return [
            'pending_balance' => $wallet->pending_balance,
            'available_balance' => $wallet->available_balance,
            'withdrawable_balance' => $wallet->withdrawable_balance,
            'total_earned' => $wallet->total_earned,
        ];
    }
}
