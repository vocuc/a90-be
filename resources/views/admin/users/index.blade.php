@extends('admin.layouts.app')
@section('title', 'Người dùng')
@section('breadcrumb')<li class="breadcrumb-item active">Người dùng</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <form class="form-inline filter-bar" method="GET">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm mr-2 mb-1" placeholder="Email, tên hoặc ID">
                <select name="role" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả vai trò</option>
                    <option value="user" @selected(($filters['role'] ?? '') === 'user')>User</option>
                    <option value="admin" @selected(($filters['role'] ?? '') === 'admin')>Admin</option>
                </select>
                <select name="status" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả trạng thái</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Hoạt động</option>
                    <option value="banned" @selected(($filters['status'] ?? '') === 'banned')>Bị khóa</option>
                </select>
                <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> Lọc</button>
            </form>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>ID</th><th>Tên</th><th>Email</th><th>Vai trò</th><th>Creator</th><th class="text-right">Số dư Credit</th><th>Trạng thái</th><th>Ngày tạo</th><th></th></tr></thead>
                <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>{{ $user->id }}</td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{!! $user->isAdmin() ? '<span class="badge badge-danger">Admin</span>' : '<span class="badge badge-light">User</span>' !!}</td>
                        <td>{{ $user->creatorProfile ? '@'.$user->creatorProfile->username : '—' }}</td>
                        <td class="text-right">@credit($user->creditWallet?->balance ?? 0)</td>
                        <td><x-admin.status :value="$user->status" /></td>
                        <td class="text-muted">{{ $user->created_at->format('d/m/Y') }}</td>
                        <td class="text-right"><a href="{{ route('admin.users.show', $user) }}" class="btn btn-xs btn-outline-primary">Chi tiết</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-3">Không có người dùng.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">{{ $users->links() }}</div>
    </div>
@endsection
