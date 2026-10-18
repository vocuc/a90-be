<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Worker: máy nội bộ (tạo ảnh, viết nội dung, đăng bài) xác thực bằng token. Admin tạo worker, xem lại / đổi token
 * (dán vào .env của máy), bật / tắt. Gắn worker với tài khoản TikTok / Facebook / Instagram ở trang Tài khoản đăng bài.
 * Không xoá worker (job cũ còn liên kết): không dùng nữa thì tắt.
 */
class WorkerController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $workers = Worker::query()->with('publishAccount')->orderBy('name')->get();

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
        $worker->load('publishAccount');
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

    /** Tắt: worker bị từ chối (401) tới khi bật lại. */
    public function toggle(Worker $worker): RedirectResponse
    {
        $worker->update(['is_active' => ! $worker->is_active]);
        $this->audit->log('admin.worker.toggle', $worker, ['is_active' => $worker->is_active]);

        $state = $worker->is_active ? 'bật' : 'tắt';

        return back()->with('success', "Đã {$state} worker \"{$worker->name}\".");
    }
}
