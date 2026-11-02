@extends('admin.layouts.app')
@section('title', 'Worker')
@section('breadcrumb')<li class="breadcrumb-item active">Worker</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <p class="text-muted small mb-0">
                Mỗi máy chạy worker (tạo ảnh, viết nội dung, đăng bài) dùng <b>1 token</b>, dán vào file <code>.env</code> của máy.
                Mỗi worker một loại: <b>Tạo ảnh</b> (nhận job tạo ảnh của các tài khoản được gắn) hoặc <b>Đăng bài</b> (shop được gắn
                chọn worker ở trang bài đăng; worker tự đăng nhập tài khoản TikTok / Facebook / Instagram trên máy).
            </p>
            <a href="{{ route('admin.workers.create') }}" class="btn btn-sm btn-primary ml-auto text-nowrap">
                <i class="fas fa-plus"></i> Thêm worker
            </a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                <tr>
                    <th>Worker</th>
                    <th>Loại</th>
                    <th>Tài khoản được gắn</th>
                    <th>Hoạt động</th>
                    <th class="text-center">Trạng thái</th>
                    <th class="text-right" style="width:200px"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($workers as $worker)
                    <tr class="{{ $worker->is_active ? '' : 'text-muted' }}">
                        <td>
                            <b>{{ $worker->name }}</b>
                            <div class="small text-muted">Tạo {{ $worker->created_at?->format('d/m/Y H:i') }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $worker->isImage() ? 'badge-info' : 'badge-primary' }}">{{ \App\Models\Worker::typeLabel($worker->type) }}</span>
                        </td>
                        <td class="small">
                            @forelse ($worker->users->take(3) as $user)
                                <div>{{ $user->email }}</div>
                            @empty
                                <span class="text-muted">Chưa gắn (không nhận việc)</span>
                            @endforelse
                            @if ($worker->users->count() > 3)
                                <div class="text-muted">+{{ $worker->users->count() - 3 }} tài khoản</div>
                            @endif
                        </td>
                        <td class="small">
                            {{ $worker->last_seen_at ? $worker->last_seen_at->diffForHumans() : 'Chưa kết nối' }}
                        </td>
                        <td class="text-center">
                            <span class="badge {{ $worker->is_active ? 'badge-success' : 'badge-secondary' }}">
                                {{ $worker->is_active ? 'Đang bật' : 'Đã tắt' }}
                            </span>
                        </td>
                        <td class="text-right text-nowrap">
                            <a href="{{ route('admin.workers.show', $worker) }}" class="btn btn-xs btn-outline-primary">
                                <i class="fas fa-key"></i> Token / tài khoản
                            </a>
                            <form method="POST" action="{{ route('admin.workers.toggle', $worker) }}" class="d-inline"
                                  @if ($worker->is_active) data-confirm="Tắt worker &quot;{{ $worker->name }}&quot;? Máy đang chạy worker này sẽ bị từ chối tới khi bật lại." @endif>
                                @csrf
                                <button class="btn btn-xs {{ $worker->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                    <i class="fas {{ $worker->is_active ? 'fa-pause' : 'fa-play' }}"></i>
                                    {{ $worker->is_active ? 'Tắt' : 'Bật' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">Chưa có worker nào.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
