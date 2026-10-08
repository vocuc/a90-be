<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCreatorProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->creatorProfile()->exists()) {
            throw new BusinessException('CREATOR_PROFILE_REQUIRED', 'Bạn cần tạo Creator Profile trước.', 403);
        }

        return $next($request);
    }
}
