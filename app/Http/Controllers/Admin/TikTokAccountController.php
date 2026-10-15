<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Http\Controllers\Controller;
use App\Models\TikTokAccount;
use App\Models\TikTokPublishJob;
use App\Models\Worker;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tài khoản TikTok của A51: mỗi tài khoản gắn 1 worker (máy đã đăng nhập sẵn tài khoản). Worker nhận job đăng bài
 * và lưu nháp vào tài khoản của mình; nhân viên vào TikTok để đăng các bài nháp.
 */
class TikTokAccountController extends Controller
{
    /** Các trường ghi vào nhật ký khi thay đổi. */
    private const FIELDS = ['name', 'username', 'worker_id', 'is_active'];

    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $accounts = TikTokAccount::query()
            ->with('worker')
            ->withCount(['posts as drafts_count' => fn ($q) => $q->where('status', TikTokPostStatus::Published)])
            ->orderBy('name')
            ->get();
        $queuedJobs = TikTokPublishJob::query()->where('status', OutputStatus::Queued)->count();

        return view('admin.tiktok-accounts.index', compact('accounts', 'queuedJobs'));
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

    /** Tắt: worker của tài khoản không nhận job đăng bài nữa (job đang chờ để worker khác nhận). */
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
            'worker_id' => $request->input('worker_id') ?: null,
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:100'],
            'worker_id' => ['nullable', 'integer', Rule::exists('workers', 'id'), Rule::unique('tiktok_accounts', 'worker_id')->ignore($account)],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.required' => 'Vui lòng nhập tên tài khoản.',
            'name.max' => 'Tên tài khoản tối đa 100 ký tự.',
            'username.max' => 'Username tối đa 100 ký tự.',
            'worker_id.exists' => 'Worker không tồn tại.',
            'worker_id.unique' => 'Worker này đang giữ tài khoản TikTok khác.',
        ]);
    }
}
