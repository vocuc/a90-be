<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Http\Controllers\Controller;
use App\Models\TikTokAccount;
use App\Models\TikTokPublishJob;
use App\Models\User;
use App\Models\Worker;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tài khoản TikTok: admin gắn 1 tài khoản A51 (email) với 1 worker (máy đã đăng nhập sẵn tài khoản TikTok).
 * Worker chỉ nhận job đăng bài của tài khoản A51 đó và lưu nháp; nhân viên vào TikTok để đăng các bài nháp.
 */
class TikTokAccountController extends Controller
{
    /** Các trường ghi vào nhật ký khi thay đổi. */
    private const FIELDS = ['name', 'username', 'user_id', 'worker_id', 'is_active'];

    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $accounts = TikTokAccount::query()
            ->with(['user', 'worker'])
            ->withCount(['posts as drafts_count' => fn ($q) => $q->where('status', TikTokPostStatus::Published)])
            ->orderBy('name')
            ->get();
        $queuedJobs = TikTokPublishJob::query()->where('status', OutputStatus::Queued)->count();
        // Bài chờ đăng của tài khoản A51 chưa được gắn worker: không ai nhận
        $unassignedJobs = TikTokPublishJob::query()
            ->where('status', OutputStatus::Queued)
            ->whereHas('post', fn ($q) => $q->whereNotIn('user_id', TikTokAccount::query()->whereNotNull('user_id')->select('user_id')))
            ->count();

        return view('admin.tiktok-accounts.index', compact('accounts', 'queuedJobs', 'unassignedJobs'));
    }

    public function create(): View
    {
        $account = new TikTokAccount(['is_active' => true]);

        return view('admin.tiktok-accounts.form', ['account' => $account, 'workers' => $this->workers(), 'drafts' => collect()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = TikTokAccount::query()->create($this->validated($request));
        $this->audit->log('admin.tiktok_account.create', $account, ['after' => $account->only(self::FIELDS)]);

        return redirect()->route('admin.tiktok-accounts.index')->with('success', "Đã thêm tài khoản TikTok \"{$account->name}\".");
    }

    public function edit(TikTokAccount $tiktokAccount): View
    {
        // Bài đã lưu nháp gần đây: nhân viên đối chiếu khi vào TikTok đăng
        $drafts = $tiktokAccount->posts()
            ->with('user')
            ->where('status', TikTokPostStatus::Published)
            ->latest('published_at')
            ->limit(20)
            ->get();

        return view('admin.tiktok-accounts.form', ['account' => $tiktokAccount, 'workers' => $this->workers(), 'drafts' => $drafts]);
    }

    public function update(Request $request, TikTokAccount $tiktokAccount): RedirectResponse
    {
        $before = $tiktokAccount->only(self::FIELDS);
        $tiktokAccount->update($this->validated($request, $tiktokAccount));
        $this->audit->log('admin.tiktok_account.update', $tiktokAccount, ['before' => $before, 'after' => $tiktokAccount->only(self::FIELDS)]);

        return redirect()->route('admin.tiktok-accounts.index')->with('success', "Đã cập nhật tài khoản TikTok \"{$tiktokAccount->name}\".");
    }

    /** Tắt: worker không nhận job đăng bài nữa, bài của tài khoản A51 nằm chờ tới khi bật lại. */
    public function toggle(TikTokAccount $tiktokAccount): RedirectResponse
    {
        $tiktokAccount->update(['is_active' => ! $tiktokAccount->is_active]);
        $this->audit->log('admin.tiktok_account.toggle', $tiktokAccount, ['is_active' => $tiktokAccount->is_active]);

        $state = $tiktokAccount->is_active ? 'bật' : 'tắt';

        return back()->with('success', "Đã {$state} tài khoản TikTok \"{$tiktokAccount->name}\".");
    }

    /** Xoá khi worker không đang đăng bài vào tài khoản. Bài đã lưu nháp giữ nguyên (mất liên kết tài khoản). */
    public function destroy(TikTokAccount $tiktokAccount): RedirectResponse
    {
        if ($tiktokAccount->publishJobs()->where('status', OutputStatus::Processing)->exists()) {
            throw ValidationException::withMessages([
                'account' => "Worker đang đăng bài vào tài khoản \"{$tiktokAccount->name}\", vui lòng chờ xong rồi xóa.",
            ]);
        }

        $this->audit->log('admin.tiktok_account.delete', $tiktokAccount, ['before' => $tiktokAccount->only(self::FIELDS)]);
        $tiktokAccount->delete();

        return redirect()->route('admin.tiktok-accounts.index')->with('success', "Đã xóa tài khoản TikTok \"{$tiktokAccount->name}\".");
    }

    /** @return Collection<int, Worker> */
    private function workers(): Collection
    {
        return Worker::query()->with('tiktokAccount')->orderBy('name')->get();
    }

    private function validated(Request $request, ?TikTokAccount $account = null): array
    {
        $request->merge([
            'username' => $request->filled('username') ? ltrim(trim($request->input('username')), '@') : null,
            'user_email' => Str::lower(trim((string) $request->input('user_email'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:100'],
            'user_email' => ['required', 'email', Rule::exists('users', 'email')],
            'worker_id' => ['required', 'integer', Rule::exists('workers', 'id'), Rule::unique('tiktok_accounts', 'worker_id')->ignore($account)],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.required' => 'Vui lòng nhập tên tài khoản.',
            'name.max' => 'Tên tài khoản tối đa 100 ký tự.',
            'username.max' => 'Username tối đa 100 ký tự.',
            'user_email.required' => 'Vui lòng nhập email tài khoản A51.',
            'user_email.email' => 'Email không hợp lệ.',
            'user_email.exists' => 'Không có tài khoản A51 với email này.',
            'worker_id.required' => 'Vui lòng chọn worker.',
            'worker_id.exists' => 'Worker không tồn tại.',
            'worker_id.unique' => 'Worker này đang được gắn với tài khoản A51 khác.',
        ]);

        // Mỗi tài khoản A51 chỉ gắn 1 worker
        $data['user_id'] = User::query()->where('email', $data['user_email'])->value('id');
        $taken = TikTokAccount::query()->where('user_id', $data['user_id'])->when($account, fn ($q) => $q->whereKeyNot($account->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['user_email' => 'Tài khoản A51 này đã được gắn với worker khác.']);
        }
        unset($data['user_email']);

        return $data;
    }
}
