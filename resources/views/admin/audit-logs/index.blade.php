@extends('admin.layouts.app')
@section('title', 'Nhật ký hệ thống')
@section('breadcrumb')<li class="breadcrumb-item active">Nhật ký hệ thống</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <form class="form-inline filter-bar" method="GET">
                <select name="action" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả hành động</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
                <input type="number" name="user_id" value="{{ $filters['user_id'] ?? '' }}" class="form-control form-control-sm mr-2 mb-1" placeholder="ID người dùng">
                <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> Lọc</button>
            </form>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>Thời gian</th><th>Người thực hiện</th><th>Hành động</th><th>Đối tượng</th><th>Chi tiết</th><th>IP</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-muted text-nowrap">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                        <td>
                            @if ($log->user_id)
                                <a href="{{ route('admin.users.show', $log->user_id) }}">{{ $users[$log->user_id]->email ?? '#'.$log->user_id }}</a>
                            @else
                                <span class="text-muted">Hệ thống</span>
                            @endif
                        </td>
                        <td><code>{{ $log->action }}</code></td>
                        <td class="small">{{ $log->resource_type }} {{ $log->resource_id ? '#'.$log->resource_id : '' }}</td>
                        <td class="small"><code class="text-muted">{{ $log->metadata ? \Illuminate\Support\Str::limit(json_encode($log->metadata, JSON_UNESCAPED_UNICODE), 160) : '' }}</code></td>
                        <td class="small text-muted">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-3">Chưa có nhật ký.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">{{ $logs->links() }}</div>
    </div>
@endsection
