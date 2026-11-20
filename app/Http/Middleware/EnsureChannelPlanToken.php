<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token cho lệnh /lap-ke-hoach (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.11): token của ADMIN có ability
 * "channel-plan" ghi rõ (token đăng nhập thường có "*" không được dùng ở đây).
 * Đi kèm: token channel-plan KHÔNG dùng được cho API thường (EnsureFullAccessToken trong nhóm auth thường).
 */
class EnsureChannelPlanToken
{
    public const ABILITY = 'channel-plan';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user || ! $user->isAdmin() || ! $token instanceof PersonalAccessToken || ! in_array(self::ABILITY, $token->abilities ?? [], true)) {
            return response()->json(['code' => 'FORBIDDEN', 'message' => 'Cần token lập kế hoạch (channel-plan) của admin.'], 403);
        }

        return $next($request);
    }
}
