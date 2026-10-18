@extends('admin.layouts.app')
@section('title', 'Worker')
@section('breadcrumb')<li class="breadcrumb-item active">Worker</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <p class="text-muted small mb-0">
                Mỗi máy chạy worker (tạo ảnh, viết nội dung, đăng bài) dùng <b>1 token</b>, dán vào file <code>.env</code> của máy.
                Worker đăng bài cần được gắn với tài khoản TikTok / Facebook / Instagram ở trang
                <a href="{{ route('admin.publish-accounts.index') }}">Tài khoản đăng bài</a>.
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
                    <th>Tài khoản đăng bài</th>
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
                            @if ($account = $worker->publishAccount)
                                <i class="fab fa-{{ $account->platform->value }}"></i> {{ $account->platform->label() }}
                                · <a href="{{ route('admin.publish-accounts.edit', $account) }}">{{ $account->name }}</a>
                            @else
                                <span class="small text-muted">Chưa gắn (worker tạo ảnh / viết nội dung, hoặc chưa dùng)</span>
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
                                <i class="fas fa-key"></i> Token
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
