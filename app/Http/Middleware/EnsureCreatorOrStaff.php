<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Creator hoặc nhân viên của shop: xem sản phẩm / nhóm creative để tạo lô (note/chia-se-tai-nguyen.txt ở repo frontend). */
class EnsureCreatorOrStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->creatorProfile()->exists() && ! $user?->staffMembership()->exists()) {
            throw new BusinessException('CREATOR_PROFILE_REQUIRED', 'Bạn cần tạo Creator Profile trước.', 403);
        }

        return $next($request);
    }
}
