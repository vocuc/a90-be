<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chủ shop xem worker admin đã gắn cho mình (note/quan-ly-worker.txt ở repo frontend). Chỉ xem; thêm / gắn / bật tắt /
 * đổi token vẫn ở trang admin. Worker đang tắt không hiện.
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

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $workers = Worker::query()
            ->assignedTo($userId)
            ->orderBy('name')
            ->get();

        $onlineSince = now()->subMinutes(self::ONLINE_MINUTES);

        return response()->json(['data' => $workers->map(function (Worker $worker) use ($onlineSince) {
            return [
                'id' => $worker->id,
                'name' => $worker->name,
                // image | publish
                'type' => $worker->type,
                'functions' => $worker->isImage() ? ['image'] : ['publish', 'video'],
                'online' => (bool) $worker->last_seen_at?->greaterThanOrEqualTo($onlineSince),
                'last_seen_at' => $worker->last_seen_at,
                // Chủ shop tự chạy máy worker nên được xem token; null với worker tạo trước khi lưu token mã hoá
                'token' => $worker->plainToken(),
            ];
        })->values()]);
    }
}
