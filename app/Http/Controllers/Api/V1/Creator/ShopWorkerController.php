<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Chủ shop xem worker admin đã gắn cho mình (note/quan-ly-worker.txt ở repo frontend), đổi tên, tạo lại token; thêm /
 * gắn / bật tắt vẫn ở trang admin. Worker đang tắt không hiện.
 *
 * Worker được gắn cho shop: tài khoản shop nằm trong các tài khoản được gắn của worker (worker_user). Mỗi worker một loại:
 * - image: nhận job tạo ảnh của shop (functions: ['image']).
 * - publish: shop chọn ở trang bài đăng để đăng bài ảnh / video, lấy ảnh tạo video (functions: ['publish', 'video']).
 *   Worker tự lo tài khoản đăng (note/gop-luong-dang-bai.txt ở repo frontend).
 */
class ShopWorkerController extends Controller
{
    /** Worker gọi API trong khoảng này coi là đang online. */
    public const ONLINE_MINUTES = 5;

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $workers = Worker::query()
            ->assignedTo($request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $workers->map(fn (Worker $worker) => $this->present($worker))->values()]);
    }

    /**
     * Đổi tên worker được gắn cho shop: chỉ để hiển thị (worker xác thực bằng token), máy đang chạy không bị ảnh hưởng.
     * Tên dùng chung (admin và các shop cùng được gắn worker đều thấy tên mới), duy nhất như ở trang admin.
     * Worker không gắn cho shop / đang tắt -> 404.
     */
    public function rename(Request $request, int $worker): JsonResponse
    {
        $model = Worker::query()->assignedTo($request->user()->id)->findOrFail($worker);

        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('workers', 'name')->ignore($model->id)],
        ], [
            'name.required' => 'Vui lòng nhập tên worker.',
            'name.max' => 'Tên worker tối đa 100 ký tự.',
            'name.unique' => 'Tên worker đã tồn tại.',
        ]);

        $old = $model->name;
        if ($old !== $data['name']) {
            $model->update(['name' => $data['name']]);
            $this->audit->log('creator.worker.rename', $model, ['from' => $old, 'to' => $model->name]);
        }

        return response()->json($this->present($model));
    }

    /**
     * Tạo lại token: token cũ hết hiệu lực ngay, máy worker phải dán token mới vào .env mới chạy tiếp. Token dùng chung
     * (mọi shop cùng được gắn worker và admin đều thấy token mới). Worker không gắn cho shop / đang tắt -> 404.
     */
    public function regenerateToken(Request $request, int $worker): JsonResponse
    {
        $model = Worker::query()->assignedTo($request->user()->id)->findOrFail($worker);

        $model->regenerateToken();
        $this->audit->log('creator.worker.regenerate_token', $model);

        return response()->json($this->present($model));
    }

    private function present(Worker $worker): array
    {
        return [
            'id' => $worker->id,
            'name' => $worker->name,
            // image | publish
            'type' => $worker->type,
            'functions' => $worker->isImage() ? ['image'] : ['publish', 'video'],
            'online' => (bool) $worker->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(self::ONLINE_MINUTES)),
            'last_seen_at' => $worker->last_seen_at,
            // Chủ shop tự chạy máy worker nên được xem token; null với worker tạo trước khi lưu token mã hoá
            'token' => $worker->plainToken(),
        ];
    }
}
