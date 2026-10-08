<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    public function log(string $action, ?Model $resource = null, array $metadata = [], ?int $userId = null): void
    {
        $request = app()->runningInConsole() ? null : request();

        AuditLog::query()->create([
            'user_id' => $userId ?? $request?->user()?->id,
            'action' => $action,
            'resource_type' => $resource ? Str::snake(class_basename($resource)) : null,
            'resource_id' => $resource?->getKey(),
            'metadata' => $metadata ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 490, '') : null,
        ]);
    }
}
