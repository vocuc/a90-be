<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Models\Worker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xác thực worker bằng header "Authorization: Bearer <token>" (token tạo bằng workers:create).
 */
class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $worker = Worker::findActiveByToken($request->bearerToken())
            ?? throw new BusinessException('WORKER_UNAUTHORIZED', 'Token worker không hợp lệ.', 401);

        $worker->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('worker', $worker);

        return $next($request);
    }
}
