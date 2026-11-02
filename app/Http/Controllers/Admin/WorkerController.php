<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Worker;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Worker: máy nội bộ (tạo ảnh, viết nội dung, đăng bài) xác thực bằng token. Admin tạo worker, xem lại / đổi token
 * (dán vào .env của máy), bật / tắt, gắn tài khoản A51 mà worker nhận job tạo ảnh (chưa gắn -> không nhận job ảnh).
 * Mỗi worker một loại (note/quan-ly-worker.txt ở repo frontend): Tạo ảnh (nhận job tạo ảnh của các tài khoản được gắn)
 * hoặc Đăng bài (shop được gắn chọn ở trang bài đăng, note/gop-luong-dang-bai.txt). Gắn nhiều tài khoản; một tài khoản
 * có nhiều worker cùng loại. Đổi loại / bỏ tài khoản bị chặn khi worker còn việc dở.
 * Không xoá worker (job cũ còn liên kết): không dùng nữa thì tắt.
 */
class WorkerController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $workers = Worker::query()->with(['users' => fn ($q) => $q->orderBy('email')])->orderBy('name')->get();

        return view('admin.workers.index', compact('workers'));
    }

    public function create(): View
    {
        return view('admin.workers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name')), 'email' => trim((string) $request->input('email'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:workers,name'],
            'type' => ['required', Rule::in(Worker::TYPES)],
            'email' => ['nullable', 'email', 'exists:users,email'],
        ], [
            'name.required' => 'Vui lòng nhập tên worker.',
            'name.max' => 'Tên worker tối đa 100 ký tự.',
            'name.unique' => 'Tên worker đã tồn tại.',
            'type.required' => 'Chọn loại worker.',
            'email.email' => 'Email không hợp lệ.',
            'email.exists' => 'Không có tài khoản nào dùng email này.',
        ]);

        [$worker] = Worker::register($data['name'], $data['type']);
        $user = $data['email'] ? User::query()->where('email', $data['email'])->firstOrFail() : null;
        if ($user) {
            $worker->users()->attach($user->id, ['created_at' => now()]);
        }
        $this->audit->log('admin.worker.create', $worker, ['name' => $worker->name, 'type' => $worker->type, 'user_id' => $user?->id]);

        return redirect()->route('admin.workers.show', $worker)
            ->with('success', "Đã tạo worker \"{$worker->name}\". Copy token bên dưới dán vào .env của máy chạy worker.");
    }

    /** Trang xem token của worker (ghi nhật ký mỗi lần xem). */
    public function show(Worker $worker): View
    {
        $worker->load(['users' => fn ($q) => $q->orderBy('email')]);
        if ($worker->plainToken() !== null) {
            $this->audit->log('admin.worker.view_token', $worker);
        }

        return view('admin.workers.show', compact('worker'));
    }

    /** Đổi tên: chỉ để hiển thị (worker xác thực bằng token), máy đang chạy không bị ảnh hưởng. */
    public function rename(Request $request, Worker $worker): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('workers', 'name')->ignore($worker->id)],
        ], [
            'name.required' => 'Vui lòng nhập tên worker.',
            'name.max' => 'Tên worker tối đa 100 ký tự.',
            'name.unique' => 'Tên worker đã tồn tại.',
        ]);

        $old = $worker->name;
        if ($old === $data['name']) {
            return back();
        }
        $worker->update(['name' => $data['name']]);
        $this->audit->log('admin.worker.rename', $worker, ['from' => $old, 'to' => $worker->name]);

        return back()->with('success', "Đã đổi tên worker \"{$old}\" thành \"{$worker->name}\".");
    }

    /** Đổi token: máy đang dùng token cũ bị từ chối ngay, phải dán token mới. */
    public function regenerate(Worker $worker): RedirectResponse
    {
        $worker->regenerateToken();
        $this->audit->log('admin.worker.regenerate_token', $worker);

        return redirect()->route('admin.workers.show', $worker)
            ->with('success', "Đã tạo token mới cho worker \"{$worker->name}\". Token cũ không dùng được nữa.");
    }

    /** Gắn tài khoản A51 (theo email): Tạo ảnh -> nhận job tạo ảnh của tài khoản; Đăng bài -> shop chọn được worker. */
    public function attachUser(Request $request, Worker $worker): RedirectResponse
    {
        $request->merge(['email' => trim((string) $request->input('email'))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ], [
            'email.required' => 'Vui lòng nhập email tài khoản.',
            'email.email' => 'Email không hợp lệ.',
            'email.exists' => 'Không có tài khoản nào dùng email này.',
        ]);

        $user = User::query()->where('email', $data['email'])->firstOrFail();
        $worker->users()->syncWithoutDetaching([$user->id => ['created_at' => now()]]);
        $this->audit->log('admin.worker.attach_user', $worker, ['user_id' => $user->id, 'email' => $user->email]);

        return back()->with('success', $worker->isImage()
            ? "Worker \"{$worker->name}\" sẽ nhận job tạo ảnh của {$user->email}."
            : "Shop {$user->email} chọn được worker \"{$worker->name}\" để đăng bài.");
    }

    /** Bỏ tài khoản khỏi worker. Chặn khi worker còn việc dở của tài khoản này (ảnh đang tạo / bài chờ đăng, đang đăng). */
    public function detachUser(Worker $worker, User $user): RedirectResponse
    {
        if ($pending = $worker->pendingJobCount($user->id)) {
            return back()->withErrors(['worker' => "Không bỏ được {$user->email}: worker còn {$pending} việc dở của tài khoản này"
                .($worker->isImage() ? ' (ảnh đang tạo).' : ' (bài chờ đăng / đang đăng). Đổi worker cho các bài đó trước.')]);
        }
        $worker->users()->detach($user->id);
        $this->audit->log('admin.worker.detach_user', $worker, ['user_id' => $user->id, 'email' => $user->email]);

        return back()->with('success', "Đã bỏ {$user->email} khỏi worker \"{$worker->name}\".");
    }

    /** Đổi loại worker (Tạo ảnh / Đăng bài). Chặn khi worker còn việc dở. */
    public function changeType(Request $request, Worker $worker): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(Worker::TYPES)]], ['type.required' => 'Chọn loại worker.']);
        if ($data['type'] === $worker->type) {
            return back();
        }
        if ($pending = $worker->pendingJobCount()) {
            return back()->withErrors(['worker' => "Không đổi loại được: worker còn {$pending} việc dở"
                .($worker->isImage() ? ' (ảnh đang tạo).' : ' (bài chờ đăng / đang đăng). Đổi worker cho các bài đó hoặc chờ đăng xong.')]);
        }
        $old = $worker->type;
        $worker->update(['type' => $data['type']]);
        $this->audit->log('admin.worker.change_type', $worker, ['from' => $old, 'to' => $worker->type]);

        return back()->with('success', "Worker \"{$worker->name}\" giờ là loại ".Worker::typeLabel($worker->type).'.');
    }

    /** Tắt: worker bị từ chối (401) tới khi bật lại. */
    public function toggle(Worker $worker): RedirectResponse
    {
        $worker->update(['is_active' => ! $worker->is_active]);
        $this->audit->log('admin.worker.toggle', $worker, ['is_active' => $worker->is_active]);

        $state = $worker->is_active ? 'bật' : 'tắt';

        return back()->with('success', "Đã {$state} worker \"{$worker->name}\".");
    }
}
