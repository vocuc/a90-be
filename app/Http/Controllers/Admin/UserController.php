<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(
        private CreditService $credits,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['user', 'admin'])],
            'status' => ['nullable', Rule::in(['active', 'banned'])],
        ]);

        $users = User::query()
            ->with(['creditWallet', 'creatorProfile'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('email', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('id', $term)))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'filters'));
    }

    public function show(User $user): View
    {
        $user->load(['creditWallet', 'creatorProfile', 'creatorWallet']);

        $ledger = CreditLedger::query()->where('user_id', $user->id)->latest('id')->limit(20)->get();
        $generations = Generation::query()->where('user_id', $user->id)->with('creative')->latest('id')->limit(10)->get();

        return view('admin.users.show', compact('user', 'ledger', 'generations'));
    }

    /** Cộng hoặc trừ Credit bằng tay. */
    public function adjustCredit(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['add', 'subtract'])],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'note' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $amount = $data['direction'] === 'add' ? $data['amount'] : -$data['amount'];

        try {
            [, $created] = $this->credits->adminAdjust($user->id, $amount, $data['idempotency_key'], $data['note'], $request->user()->id);
        } catch (BusinessException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('success', $created
            ? ($amount > 0 ? 'Đã cộng ' : 'Đã trừ ').number_format(abs($amount), 0, ',', '.').' Credit.'
            : 'Yêu cầu này đã được xử lý trước đó, không ghi thêm.');
    }

    public function toggleBan(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id || $user->isAdmin()) {
            return back()->withErrors(['status' => 'Không thể khóa tài khoản admin.']);
        }

        $banned = $user->status === UserStatus::Active;
        $user->forceFill(['status' => $banned ? UserStatus::Banned : UserStatus::Active])->save();

        if ($banned) {
            $user->tokens()->delete(); // đăng xuất khỏi mọi thiết bị
        }

        $this->audit->log($banned ? 'admin.user.ban' : 'admin.user.unban', $user);

        return back()->with('success', $banned ? 'Đã khóa tài khoản.' : 'Đã mở khóa tài khoản.');
    }
}
