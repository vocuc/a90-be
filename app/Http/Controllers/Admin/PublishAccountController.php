<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OutputStatus;
use App\Enums\PublishDriver;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Models\PublishAccount;
use App\Models\SocialPublishJob;
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
 * Tài khoản đăng bài: admin gắn 1 tài khoản A51 (email) với 1 worker (máy đã đăng nhập sẵn tài khoản TikTok /
 * Facebook / Instagram). Mỗi tài khoản A51 có tối đa 1 tài khoản / nền tảng. Worker chỉ nhận job đăng bài của
 * nền tảng và tài khoản A51 đó (TikTok: lưu nháp, nhân viên vào TikTok đăng các bài nháp).
 * Dòng driver api (TikTok người dùng tự kết nối) chỉ hiện ở danh sách, admin không sửa / bật tắt / xoá ở đây.
 */
class PublishAccountController extends Controller
{
    /** Các trường ghi vào nhật ký khi thay đổi. */
    private const FIELDS = ['platform', 'name', 'username', 'target_url', 'user_id', 'worker_id', 'is_active'];

    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $accounts = PublishAccount::query()
            ->with(['user', 'worker'])
            ->withCount(['publishJobs as published_count' => fn ($q) => $q->where('status', OutputStatus::Completed)])
            ->orderBy('platform')
            ->orderBy('name')
            ->get();
        $queuedJobs = SocialPublishJob::query()->where('status', OutputStatus::Queued)->count();
        // Job chờ của tài khoản A51 chưa được gắn worker cho nền tảng đó: không ai nhận
        $unassignedJobs = SocialPublishJob::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_publish_jobs.social_post_id')
            ->where('social_publish_jobs.status', OutputStatus::Queued)
            ->where('social_publish_jobs.driver', PublishDriver::Worker)
            ->whereNotExists(fn ($q) => $q->from('publish_accounts')
                ->where('publish_accounts.driver', PublishDriver::Worker)
                ->whereColumn('publish_accounts.user_id', 'social_posts.user_id')
                ->whereColumn('publish_accounts.platform', 'social_publish_jobs.platform'))
            ->count();

        return view('admin.publish-accounts.index', compact('accounts', 'queuedJobs', 'unassignedJobs'));
    }

    public function create(): View
    {
        $account = new PublishAccount(['platform' => SocialPlatform::Tiktok, 'is_active' => true]);

        return view('admin.publish-accounts.form', ['account' => $account, 'workers' => $this->workers(), 'recentJobs' => collect()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = PublishAccount::query()->create($this->validated($request));
        $this->audit->log('admin.publish_account.create', $account, ['after' => $account->only(self::FIELDS)]);

        return redirect()->route('admin.publish-accounts.index')->with('success', "Đã thêm tài khoản {$account->platform->label()} \"{$account->name}\".");
    }

    public function edit(PublishAccount $publishAccount): View
    {
        abort_if($publishAccount->isApi(), 404);
        // Bài đã đăng gần đây: nhân viên đối chiếu (TikTok: vào TikTok đăng các bài nháp)
        $recentJobs = $publishAccount->publishJobs()
            ->with('post.user')
            ->where('status', OutputStatus::Completed)
            ->latest('finished_at')
            ->limit(20)
            ->get();

        return view('admin.publish-accounts.form', ['account' => $publishAccount, 'workers' => $this->workers(), 'recentJobs' => $recentJobs]);
    }

    public function update(Request $request, PublishAccount $publishAccount): RedirectResponse
    {
        abort_if($publishAccount->isApi(), 404);
        $before = $publishAccount->only(self::FIELDS);
        $publishAccount->update($this->validated($request, $publishAccount));
        $this->audit->log('admin.publish_account.update', $publishAccount, ['before' => $before, 'after' => $publishAccount->only(self::FIELDS)]);

        return redirect()->route('admin.publish-accounts.index')->with('success', "Đã cập nhật tài khoản {$publishAccount->platform->label()} \"{$publishAccount->name}\".");
    }

    /** Tắt: worker không nhận job đăng bài nữa, bài của tài khoản A51 nằm chờ tới khi bật lại. */
    public function toggle(PublishAccount $publishAccount): RedirectResponse
    {
        abort_if($publishAccount->isApi(), 404);
        $publishAccount->update(['is_active' => ! $publishAccount->is_active]);
        $this->audit->log('admin.publish_account.toggle', $publishAccount, ['is_active' => $publishAccount->is_active]);

        $state = $publishAccount->is_active ? 'bật' : 'tắt';

        return back()->with('success', "Đã {$state} tài khoản {$publishAccount->platform->label()} \"{$publishAccount->name}\".");
    }

    /** Xoá khi worker không đang đăng bài vào tài khoản. Bài đã đăng giữ nguyên (job mất liên kết tài khoản). */
    public function destroy(PublishAccount $publishAccount): RedirectResponse
    {
        abort_if($publishAccount->isApi(), 404);
        if ($publishAccount->publishJobs()->where('status', OutputStatus::Processing)->exists()) {
            throw ValidationException::withMessages([
                'account' => "Worker đang đăng bài vào tài khoản \"{$publishAccount->name}\", vui lòng chờ xong rồi xóa.",
            ]);
        }

        $this->audit->log('admin.publish_account.delete', $publishAccount, ['before' => $publishAccount->only(self::FIELDS)]);
        $publishAccount->delete();

        return redirect()->route('admin.publish-accounts.index')->with('success', "Đã xóa tài khoản {$publishAccount->platform->label()} \"{$publishAccount->name}\".");
    }

    /** @return Collection<int, Worker> */
    private function workers(): Collection
    {
        return Worker::query()->with('publishAccount')->orderBy('name')->get();
    }

    private function validated(Request $request, ?PublishAccount $account = null): array
    {
        $request->merge([
            'username' => $request->filled('username') ? ltrim(trim($request->input('username')), '@') : null,
            'target_url' => $request->filled('target_url') ? trim($request->input('target_url')) : null,
            'user_email' => Str::lower(trim((string) $request->input('user_email'))),
        ]);

        $data = $request->validate([
            'platform' => ['required', Rule::enum(SocialPlatform::class)],
            'name' => ['required', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:100'],
            'target_url' => ['nullable', 'required_if:platform,'.SocialPlatform::Facebook->value, 'string', 'max:500', 'url:http,https'],
            'user_email' => ['required', 'email', Rule::exists('users', 'email')],
            'worker_id' => ['required', 'integer', Rule::exists('workers', 'id'), Rule::unique('publish_accounts', 'worker_id')->ignore($account)],
            'is_active' => ['required', 'boolean'],
        ], [
            'platform.required' => 'Vui lòng chọn nền tảng.',
            'platform.enum' => 'Nền tảng không hợp lệ.',
            'name.required' => 'Vui lòng nhập tên tài khoản.',
            'name.max' => 'Tên tài khoản tối đa 100 ký tự.',
            'username.max' => 'Username tối đa 100 ký tự.',
            'target_url.required_if' => 'Vui lòng nhập link trang / nhóm / trang cá nhân Facebook sẽ đăng bài.',
            'target_url.url' => 'Link không hợp lệ.',
            'target_url.max' => 'Link tối đa 500 ký tự.',
            'user_email.required' => 'Vui lòng nhập email tài khoản A51.',
            'user_email.email' => 'Email không hợp lệ.',
            'user_email.exists' => 'Không có tài khoản A51 với email này.',
            'worker_id.required' => 'Vui lòng chọn worker.',
            'worker_id.exists' => 'Worker không tồn tại.',
            'worker_id.unique' => 'Worker này đang được gắn với tài khoản khác.',
        ]);

        // Mỗi tài khoản A51 chỉ có 1 tài khoản / nền tảng
        $data['user_id'] = User::query()->where('email', $data['user_email'])->value('id');
        $taken = PublishAccount::query()
            ->where('driver', PublishDriver::Worker)
            ->where('user_id', $data['user_id'])
            ->where('platform', $data['platform'])
            ->when($account, fn ($q) => $q->whereKeyNot($account->id))
            ->exists();
        if ($taken) {
            $label = SocialPlatform::from($data['platform'])->label();
            throw ValidationException::withMessages(['user_email' => "Tài khoản A51 này đã có tài khoản {$label} khác."]);
        }
        unset($data['user_email']);

        return $data;
    }
}
