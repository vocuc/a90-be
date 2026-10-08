<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CreativeStatus;
use App\Enums\LedgerType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\AiCreative;
use App\Models\CreatorApiKey;
use App\Models\CreatorProfile;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $completed = fn ($from) => Transaction::query()
            ->where('status', TransactionStatus::Completed)
            ->where('completed_at', '>=', $from);

        $stats = [
            'users' => User::query()->count(),
            'users_today' => User::query()->where('created_at', '>=', $today)->count(),
            'creators' => CreatorProfile::query()->count(),
            'creatives_published' => AiCreative::query()->where('status', CreativeStatus::Published)->count(),
            'creatives_hidden' => AiCreative::query()->whereNotNull('hidden_at')->count(),
            'images_today' => GenerationOutput::query()->where('status', 'completed')->where('finished_at', '>=', $today)->count(),
            'images_failed_today' => GenerationOutput::query()->where('status', 'failed')->where('finished_at', '>=', $today)->count(),
            'gmv_today' => (int) $completed($today)->sum('amount'),
            'gmv_month' => (int) $completed($month)->sum('amount'),
            'fee_today' => (int) $completed($today)->sum('platform_fee_amount'),
            'fee_month' => (int) $completed($month)->sum('platform_fee_amount'),
            'topup_month' => (int) CreditLedger::query()
                ->where('type', LedgerType::AdminTopup)->where('created_at', '>=', $month)->sum('amount'),
            'processing' => GenerationOutput::query()->whereIn('status', ['queued', 'processing'])->count(),
            'invalid_keys' => CreatorApiKey::query()->where('status', 'invalid')->count(),
        ];

        $recentGenerations = Generation::query()
            ->with(['user', 'creative'])
            ->latest('id')
            ->limit(10)
            ->get();

        $topCreatives = AiCreative::query()
            ->with('creator.creatorProfile')
            ->orderByDesc('usage_count')
            ->limit(5)
            ->get();

        return view('admin.dashboard', compact('stats', 'recentGenerations', 'topCreatives'));
    }
}
