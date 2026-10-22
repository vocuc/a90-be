<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Worker;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Worker: máy nội bộ (tạo ảnh, viết nội dung, đăng bài) xác thực bằng token. Admin tạo worker, xem lại / đổi token
 * (dán vào .env của máy), bật / tắt, gắn tài khoản A51 mà worker nhận job tạo ảnh (chưa gắn -> không nhận job ảnh).
 * Gắn worker với tài khoản TikTok / Facebook / Instagram ở trang Tài khoản đăng bài.
 * Không xoá worker (job cũ còn liên kết): không dùng nữa thì tắt.
 */
class WorkerController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $workers = Worker::query()->with('publishAccount')->withCount('imageUsers')->orderBy('name')->get();

        return view('admin.workers.index', compact('workers'));
    }

    public function create(): View
    {
        return view('admin.workers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:workers,name'],
        ], [
            'name.required' => 'Vui lòng nhập tên worker.',
            'name.max' => 'Tên worker tối đa 100 ký tự.',
            'name.unique' => 'Tên worker đã tồn tại.',
        ]);

        [$worker] = Worker::register($data['name']);
        $this->audit->log('admin.worker.create', $worker, ['name' => $worker->name]);

        return redirect()->route('admin.workers.show', $worker)
            ->with('success', "Đã tạo worker \"{$worker->name}\". Copy token bên dưới dán vào .env của máy chạy worker.");
    }

    /** Trang xem token của worker (ghi nhật ký mỗi lần xem). */
    public function show(Worker $worker): View
    {
        $worker->load(['publishAccount', 'imageUsers' => fn ($q) => $q->orderBy('email')]);
        if ($worker->plainToken() !== null) {
            $this->audit->log('admin.worker.view_token', $worker);
        }

        return view('admin.workers.show', compact('worker'));
    }

    /** Đổi token: máy đang dùng token cũ bị từ chối ngay, phải dán token mới. */
    public function regenerate(Worker $worker): RedirectResponse
    {
        $worker->regenerateToken();
        $this->audit->log('admin.worker.regenerate_token', $worker);

        return redirect()->route('admin.workers.show', $worker)
            ->with('success', "Đã tạo token mới cho worker \"{$worker->name}\". Token cũ không dùng được nữa.");
    }

    /** Gắn tài khoản A51 (theo email) vào worker: worker nhận job tạo ảnh của tài khoản này. */
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
        $worker->imageUsers()->syncWithoutDetaching([$user->id => ['created_at' => now()]]);
        $this->audit->log('admin.worker.attach_user', $worker, ['user_id' => $user->id, 'email' => $user->email]);

        return back()->with('success', "Worker \"{$worker->name}\" sẽ nhận job tạo ảnh của {$user->email}.");
    }

    /** Bỏ tài khoản khỏi worker: job đang chờ của tài khoản này không giao cho worker nữa (job đang làm dở vẫn trả kết quả được). */
    public function detachUser(Worker $worker, User $user): RedirectResponse
    {
        $worker->imageUsers()->detach($user->id);
        $this->audit->log('admin.worker.detach_user', $worker, ['user_id' => $user->id, 'email' => $user->email]);

        return back()->with('success', "Worker \"{$worker->name}\" không nhận job tạo ảnh của {$user->email} nữa.");
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
