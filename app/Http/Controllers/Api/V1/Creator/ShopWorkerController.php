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
 * Worker được gắn cho shop khi có ít nhất một chức năng của shop:
 * - image: tài khoản shop nằm trong tài khoản tạo ảnh của worker (worker_user)
 * - publish + video: worker đăng bài ảnh / video và tạo video cho shop (video_user_id). Worker tự lo tài khoản đăng
 *   (note/gop-luong-dang-bai.txt ở repo frontend).
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
            ->with(['imageUsers' => fn ($u) => $u->whereKey($userId)])
            ->orderBy('name')
            ->get();

        $onlineSince = now()->subMinutes(self::ONLINE_MINUTES);

        return response()->json(['data' => $workers->map(function (Worker $worker) use ($userId, $onlineSince) {
            $posts = $worker->video_user_id === $userId;

            return [
                'id' => $worker->id,
                'name' => $worker->name,
                'functions' => array_values(array_filter([
                    $worker->imageUsers->isNotEmpty() ? 'image' : null,
                    $posts ? 'publish' : null,
                    $posts ? 'video' : null,
                ])),
                'online' => (bool) $worker->last_seen_at?->greaterThanOrEqualTo($onlineSince),
                'last_seen_at' => $worker->last_seen_at,
                // Chủ shop tự chạy máy worker nên được xem token; null với worker tạo trước khi lưu token mã hoá
                'token' => $worker->plainToken(),
            ];
        })->values()]);
    }
}
