@extends('admin.layouts.app')
@section('title', $user->name)
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Người dùng</a></li>
    <li class="breadcrumb-item active">#{{ $user->id }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-4">
            <div class="card card-primary card-outline">
                <div class="card-body box-profile">
                    <h3 class="profile-username text-center">{{ $user->name }}</h3>
                    <p class="text-muted text-center mb-2">{{ $user->email }}</p>
                    <p class="text-center">
                        <x-admin.status :value="$user->status" />
                        @if ($user->isAdmin()) <span class="badge badge-danger">Admin</span> @endif
                    </p>
                    <ul class="list-group list-group-unbordered mb-3">
                        <li class="list-group-item"><b>Ngày tạo</b> <span class="float-right">{{ $user->created_at->format('d/m/Y H:i') }}</span></li>
                        @if ($user->creatorProfile)
                            <li class="list-group-item"><b>Creator</b> <span class="float-right">{{ '@'.$user->creatorProfile->username }} @if ($user->creatorProfile->verified)<i class="fas fa-check-circle text-primary"></i>@endif</span></li>
                        @endif
                    </ul>
                    @unless ($user->isAdmin())
                        <form method="POST" action="{{ route('admin.users.toggle-ban', $user) }}"
                              data-confirm="{{ $user->isActive() ? 'Khóa tài khoản này? Người dùng sẽ bị đăng xuất khỏi mọi thiết bị.' : 'Mở khóa tài khoản này?' }}">
                            @csrf
                            <button class="btn btn-block {{ $user->isActive() ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                <i class="fas {{ $user->isActive() ? 'fa-lock' : 'fa-unlock' }}"></i>
                                {{ $user->isActive() ? 'Khóa tài khoản' : 'Mở khóa tài khoản' }}
                            </button>
                        </form>
                    @endunless
                    @if ($user->id !== auth()->id() && ($user->isAdmin() || $user->isActive()))
                        <form method="POST" action="{{ route('admin.users.toggle-admin', $user) }}" class="mt-2"
                              data-confirm="{{ $user->isAdmin() ? 'Thu hồi quyền admin của '.$user->email.'?' : 'Cấp quyền admin cho '.$user->email.'? Tài khoản này sẽ có toàn quyền quản trị.' }}">
                            @csrf
                            <button class="btn btn-block {{ $user->isAdmin() ? 'btn-outline-secondary' : 'btn-outline-warning' }}">
                                <i class="fas {{ $user->isAdmin() ? 'fa-user-minus' : 'fa-user-shield' }}"></i>
                                {{ $user->isAdmin() ? 'Thu hồi quyền admin' : 'Cấp quyền admin' }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Lượt tạo ảnh gần đây</h3></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>#</th><th>Creative</th><th>Loại</th><th>Ảnh</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
                        <tbody>
                        @forelse ($generations as $generation)
                            <tr>
                                <td><a href="{{ route('admin.generations.show', $generation) }}">{{ $generation->id }}</a></td>
                                <td>{{ \Illuminate\Support\Str::limit($generation->creative->title, 30) }}</td>
                                <td><x-admin.status :value="$generation->type" /></td>
                                <td>{{ $generation->success_count }}/{{ $generation->quantity }}</td>
                                <td><x-admin.status :value="$generation->status" /></td>
                                <td class="text-muted">{{ $generation->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">Chưa có lượt tạo ảnh.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
