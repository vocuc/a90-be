<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(
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
            ->with(['creatorProfile'])
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
        $user->load(['creatorProfile']);

        $generations = Generation::query()->where('user_id', $user->id)->with('creative')->latest('id')->limit(10)->get();

        return view('admin.users.show', compact('user', 'generations'));
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

    /** Cấp hoặc thu hồi quyền admin. */
    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['role' => 'Không thể tự thay đổi quyền của chính mình.']);
        }

        $granting = ! $user->isAdmin();

        if ($granting && ! $user->isActive()) {
            return back()->withErrors(['role' => 'Không thể cấp quyền admin cho tài khoản đang bị khóa.']);
        }

        // Admin bị thu hồi sẽ bị đăng xuất khỏi trang quản trị ở request kế tiếp (EnsureAdminSession).
        $user->forceFill(['role' => $granting ? UserRole::Admin : UserRole::User])->save();

        $this->audit->log($granting ? 'admin.user.grant_admin' : 'admin.user.revoke_admin', $user);

        return back()->with('success', $granting ? 'Đã cấp quyền admin.' : 'Đã thu hồi quyền admin.');
    }
}
