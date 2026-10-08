<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            throw new BusinessException('FORBIDDEN', 'Bạn không có quyền thực hiện thao tác này.', 403);
        }

        return $next($request);
    }
}
