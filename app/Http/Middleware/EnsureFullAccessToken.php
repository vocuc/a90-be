<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn token giới hạn quyền (vd "token kế hoạch", ability planner) gọi các API thường: chỉ token đăng nhập (ability *)
 * đi qua. Token kế hoạch chỉ gọi được nhóm /planner/* (middleware abilities:planner).
 */
class EnsureFullAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! in_array('*', $token->abilities ?? [], true)) {
            throw new BusinessException('TOKEN_SCOPE_DENIED', 'Token này chỉ dùng cho API kế hoạch nội dung.', 403);
        }

        return $next($request);
    }
}
