<?php

namespace App\Http\Middleware;

use App\Models\Worker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token cho lệnh /lap-ke-hoach (và skill /gan-tag-san-pham): token của WORKER loại "Điều phối" (bỏ token admin
 * channel-plan, người dùng chốt 2026-11-25). Worker chỉ đọc / ghi kênh, bài, sản phẩm của các shop admin đã gắn cho nó
 * (ChannelPlanApiController kiểm tra từng shop qua allowsShop). Request mang worker ở attribute "worker".
 */
class EnsureChannelPlanToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $worker = Worker::findActiveByToken($request->bearerToken());
        if (! $worker || $worker->type !== Worker::TYPE_DISPATCH) {
            return response()->json(['code' => 'FORBIDDEN', 'message' => 'Cần token worker loại Điều phối (đang bật).'], 403);
        }
        $worker->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('worker', $worker);

        return $next($request);
    }

    /** Worker đang gọi được làm việc cho shop này (admin đã gắn shop cho worker). */
    public static function allowsShop(Request $request, int $shopId): bool
    {
        $worker = $request->attributes->get('worker');

        return $worker instanceof Worker && $worker->users()->whereKey($shopId)->exists();
    }
}
