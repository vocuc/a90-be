<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerType;
use App\Enums\RevenueStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\CreatorRevenue;
use App\Models\CreditLedger;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Các màn hình chỉ đọc về tiền: Transaction, sổ cái Credit, doanh thu Creator. */
class FinanceController extends Controller
{
    public function transactions(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
        ]);

        $transactions = Transaction::query()
            ->with(['user', 'creative'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('code', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%"))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.finance.transactions', compact('transactions', 'filters'));
    }

    public function ledger(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(LedgerType::class)],
        ]);

        $entries = CreditLedger::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereIn('user_id',
                User::query()->where('email', 'like', "%{$term}%")->orWhere('id', $term)->select('id')))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $users = User::query()
            ->whereIn('id', $entries->pluck('user_id')->merge($entries->pluck('created_by'))->filter()->unique())
            ->get()
            ->keyBy('id');

        return view('admin.finance.ledger', compact('entries', 'filters', 'users'));
    }

    public function revenues(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(RevenueStatus::class)],
        ]);

        $revenues = CreatorRevenue::query()
            ->with(['transaction.creative', 'creator.creatorProfile'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('creator.creatorProfile', fn ($p) => $p
                ->where('username', 'like', "%{$term}%")
                ->orWhere('shop_name', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.finance.revenues', compact('revenues', 'filters'));
    }
}
