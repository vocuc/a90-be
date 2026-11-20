<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token giới hạn (vd token "channel-plan" cho lệnh /lap-ke-hoach) chỉ dùng được ở API của nó, không dùng được cho API
 * thường của người dùng: token phải có ability "*" (token đăng nhập).
 */
class EnsureFullAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! in_array('*', $token->abilities ?? [], true)) {
            return response()->json(['code' => 'FORBIDDEN', 'message' => 'Token này không dùng được cho API này.'], 403);
        }

        return $next($request);
    }
}
