<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::query()
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', "{$action}%"))
            ->when($filters['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $users = User::query()->whereIn('id', $logs->pluck('user_id')->filter()->unique())->get()->keyBy('id');
        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action');

        return view('admin.audit-logs.index', compact('logs', 'filters', 'users', 'actions'));
    }
}
