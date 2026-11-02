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
 * Shop đăng bài (video_user_id): worker đăng bài ảnh / video được giao + tạo video cho đúng 1 shop
 *   (note/gop-luong-dang-bai.txt ở repo frontend). Worker tự đăng nhập tài khoản mạng xã hội trên máy.
 * Không xoá worker (job cũ còn liên kết): không dùng nữa thì tắt.
 */
class WorkerController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $workers = Worker::query()->with(['videoUser'])->withCount('imageUsers')->orderBy('name')->get();

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
        $worker->load(['videoUser', 'imageUsers' => fn ($q) => $q->orderBy('email')]);
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

    /** Gắn shop (theo email) worker đăng bài + tạo video cho. Mỗi worker 1 shop, gắn mới thay shop cũ. */
    public function setVideoUser(Request $request, Worker $worker): RedirectResponse
    {
        $request->merge(['video_email' => trim((string) $request->input('video_email'))]);
        $data = $request->validate([
            'video_email' => ['required', 'email', 'exists:users,email'],
        ], [
            'video_email.required' => 'Vui lòng nhập email tài khoản.',
            'video_email.email' => 'Email không hợp lệ.',
            'video_email.exists' => 'Không có tài khoản nào dùng email này.',
        ]);

        $user = User::query()->where('email', $data['video_email'])->firstOrFail();
        $worker->update(['video_user_id' => $user->id]);
        $this->audit->log('admin.worker.set_video_user', $worker, ['user_id' => $user->id, 'email' => $user->email]);

        return back()->with('success', "Worker \"{$worker->name}\" sẽ đăng bài + tạo video cho {$user->email}.");
    }

    /** Bỏ shop đăng bài: shop không chọn được worker để đăng bài, worker không lấy được ảnh tạo video nữa. */
    public function clearVideoUser(Worker $worker): RedirectResponse
    {
        $email = $worker->videoUser?->email;
        $worker->update(['video_user_id' => null]);
        $this->audit->log('admin.worker.clear_video_user', $worker, ['email' => $email]);

        return back()->with('success', "Worker \"{$worker->name}\" không đăng bài / tạo video cho shop nào nữa.");
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
