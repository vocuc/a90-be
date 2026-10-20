@extends('admin.layouts.app')
@section('title', 'Tài khoản đăng bài')
@section('breadcrumb')<li class="breadcrumb-item active">Tài khoản đăng bài</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <p class="text-muted small mb-0">
                Mỗi dòng gắn <b>1 tài khoản A51</b> với <b>1 worker</b> (máy đã đăng nhập sẵn tài khoản TikTok / Facebook / Instagram).
                Worker chỉ nhận bài của tài khoản A51 đó trên đúng nền tảng; TikTok chỉ <b>lưu nháp</b>, vào TikTok để đăng các bài nháp.
                Đang có <b>{{ $queuedJobs }}</b> bài chờ worker nhận.
                @if ($unassignedJobs)
                    <span class="text-danger">Trong đó <b>{{ $unassignedJobs }}</b> bài của tài khoản A51 chưa được gắn worker.</span>
                @endif
            </p>
            <a href="{{ route('admin.publish-accounts.create') }}" class="btn btn-sm btn-primary ml-auto text-nowrap">
                <i class="fas fa-plus"></i> Thêm tài khoản
            </a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                <tr>
                    <th>Nền tảng</th>
                    <th>Tài khoản</th>
                    <th>Tài khoản A51</th>
                    <th>Worker</th>
                    <th class="text-center">Bài đã đăng</th>
                    <th class="text-center">Trạng thái</th>
                    <th class="text-right" style="width:230px"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($accounts as $account)
                    <tr class="{{ $account->is_active ? '' : 'text-muted' }}">
                        <td class="text-nowrap"><i class="fab fa-{{ $account->platform->value }}"></i> {{ $account->platform->label() }}</td>
                        <td>
                            <b>{{ $account->name }}</b>
                            @if ($account->username)<div class="small text-muted">{{ '@'.$account->username }}</div>@endif
                            @if ($account->target_url)<div class="small text-truncate" style="max-width:260px"><a href="{{ $account->target_url }}" target="_blank" rel="noopener">{{ $account->target_url }}</a></div>@endif
                        </td>
                        <td>
                            @if ($account->user)
                                <a href="{{ route('admin.users.show', $account->user) }}">{{ $account->user->email }}</a>
                            @else
                                <span class="text-warning small">Chưa gắn tài khoản A51</span>
                            @endif
                        </td>
                        <td>
                            @if ($account->isApi())
                                <span class="badge badge-info">API</span> người dùng tự kết nối
                                <div class="small {{ $account->isUsable() ? 'text-muted' : 'text-danger' }}">
                                    {{ $account->isUsable() ? 'Đang kết nối' : 'Mất kết nối' }}{{ $account->connected_at ? ' · từ '.$account->connected_at->format('d/m/Y') : '' }}
                                </div>
                            @elseif ($account->worker)
                                {{ $account->worker->name }}
                                <div class="small text-muted">
                                    {{ $account->worker->last_seen_at ? 'Hoạt động '.$account->worker->last_seen_at->diffForHumans() : 'Chưa kết nối' }}
                                    @unless ($account->worker->is_active) · <span class="text-danger">worker đã tắt</span>@endunless
                                </div>
                            @else
                                <span class="text-warning small">Chưa gắn worker</span>
                            @endif
                        </td>
                        <td class="text-center">{{ $account->published_count }}</td>
                        <td class="text-center">
                            <span class="badge {{ $account->is_active ? 'badge-success' : 'badge-secondary' }}">
                                {{ $account->is_active ? 'Đang bật' : 'Đã tắt' }}
                            </span>
                        </td>
                        <td class="text-right text-nowrap">
                            @unless ($account->isApi())
                            <a href="{{ route('admin.publish-accounts.edit', $account) }}" class="btn btn-xs btn-outline-primary">
                                <i class="fas fa-edit"></i> Sửa
                            </a>
                            <form method="POST" action="{{ route('admin.publish-accounts.toggle', $account) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-xs {{ $account->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                    <i class="fas {{ $account->is_active ? 'fa-pause' : 'fa-play' }}"></i>
                                    {{ $account->is_active ? 'Tắt' : 'Bật' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.publish-accounts.destroy', $account) }}" class="d-inline"
                                  data-confirm="Xóa tài khoản {{ $account->platform->label() }} &quot;{{ $account->name }}&quot;? Bài đã đăng vẫn giữ trên {{ $account->platform->label() }}.">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger"><i class="fas fa-trash"></i> Xóa</button>
                            </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">Chưa có tài khoản đăng bài. Bài người dùng bấm đăng sẽ chờ tới khi tài khoản A51 của họ được gắn worker cho nền tảng đó.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
