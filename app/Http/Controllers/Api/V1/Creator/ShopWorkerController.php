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
 * - video: worker lấy ảnh tạo video từ tài khoản shop (video_user_id)
 * - publish: worker giữ tài khoản đăng bài của shop (publish_accounts.worker_id)
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
            ->with([
                'imageUsers' => fn ($u) => $u->whereKey($userId),
                'publishAccount' => fn ($a) => $a->where('user_id', $userId),
            ])
            ->orderBy('name')
            ->get();

        $onlineSince = now()->subMinutes(self::ONLINE_MINUTES);

        return response()->json(['data' => $workers->map(function (Worker $worker) use ($userId, $onlineSince) {
            $account = $worker->publishAccount;

            return [
                'id' => $worker->id,
                'name' => $worker->name,
                'functions' => array_values(array_filter([
                    $worker->imageUsers->isNotEmpty() ? 'image' : null,
                    $account ? 'publish' : null,
                    $worker->video_user_id === $userId ? 'video' : null,
                ])),
                'publish_account' => $account ? [
                    'platform' => $account->platform->value,
                    'platform_label' => $account->platform->label(),
                    'name' => $account->displayName(),
                ] : null,
                'online' => (bool) $worker->last_seen_at?->greaterThanOrEqualTo($onlineSince),
                'last_seen_at' => $worker->last_seen_at,
                // Chủ shop tự chạy máy worker nên được xem token; null với worker tạo trước khi lưu token mã hoá
                'token' => $worker->plainToken(),
            ];
        })->values()]);
    }
}
