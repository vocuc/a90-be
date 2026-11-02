<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Creator\ShopWorkerController;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Worker tạo ảnh (loại image, đang bật) admin gắn cho tài khoản, để chọn khi tạo ảnh / tạo lô
 * (note/chon-worker-tao-anh.txt ở repo frontend). Mọi tài khoản đã đăng nhập (không cần shop), không trả token.
 */
class ImageWorkerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $onlineSince = now()->subMinutes(ShopWorkerController::ONLINE_MINUTES);

        $workers = Worker::query()
            ->assignedTo($request->user()->id, Worker::TYPE_IMAGE)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $workers->map(fn (Worker $worker) => [
            'id' => $worker->id,
            'name' => $worker->name,
            'online' => (bool) $worker->last_seen_at?->greaterThanOrEqualTo($onlineSince),
            'last_seen_at' => $worker->last_seen_at,
        ])->values()]);
    }
}
