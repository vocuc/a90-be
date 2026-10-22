@php
    $token = $worker->plainToken();
@endphp
@extends('admin.layouts.app')
@section('title', 'Worker '.$worker->name)
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.workers.index') }}">Worker</a></li>
    <li class="breadcrumb-item active">{{ $worker->name }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        Token của worker <b>{{ $worker->name }}</b>
                        <span class="badge ml-1 {{ $worker->is_active ? 'badge-success' : 'badge-secondary' }}">{{ $worker->is_active ? 'Đang bật' : 'Đã tắt' }}</span>
                    </h3>
                </div>
                <div class="card-body">
                    @if ($token !== null)
                        <div class="input-group">
                            <input type="password" id="worker-token" readonly value="{{ $token }}" class="form-control text-monospace">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" id="toggle-token"><i class="fas fa-eye"></i> Hiện</button>
                                <button type="button" class="btn btn-primary" id="copy-token"><i class="fas fa-copy"></i> Copy</button>
                            </div>
                        </div>
                        <small class="form-text text-muted">
                            Dán vào <code>.env</code> của máy chạy worker, vd <code>PHONE1_TIKTOK_TOKEN=...</code> (điện thoại)
                            hoặc <code>TIKTOK_WORKER_TOKEN=...</code> (Chrome). Không gửi token cho người ngoài: ai có token đều nhận được job của worker này.
                        </small>
                    @else
                        <div class="alert alert-warning mb-0">
                            Worker này được tạo trước khi hệ thống lưu token nên không xem lại được.
                            Bấm <b>Tạo token mới</b> để lấy token (máy đang dùng token cũ phải dán token mới).
                        </div>
                    @endif
                </div>
                <div class="card-footer d-flex">
                    <form method="POST" action="{{ route('admin.workers.regenerate', $worker) }}"
                          data-confirm="Tạo token mới cho worker &quot;{{ $worker->name }}&quot;? Token cũ không dùng được nữa, máy đang chạy worker này phải dán token mới.">
                        @csrf
                        <button class="btn btn-outline-danger"><i class="fas fa-sync-alt"></i> Tạo token mới</button>
                    </form>
                    <a href="{{ route('admin.workers.index') }}" class="btn btn-link ml-auto">Về danh sách</a>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Tài khoản nhận job tạo ảnh</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        Worker tạo ảnh chỉ nhận job do các tài khoản dưới đây tạo. <b>Chưa gắn tài khoản nào thì worker không nhận job ảnh nào.</b>
                    </p>
                    <form method="POST" action="{{ route('admin.workers.users.attach', $worker) }}" class="mb-3">
                        @csrf
                        <div class="input-group">
                            <input type="email" name="email" value="{{ old('email') }}" placeholder="Email tài khoản A51"
                                   class="form-control @error('email') is-invalid @enderror" required>
                            <div class="input-group-append">
                                <button class="btn btn-primary"><i class="fas fa-plus"></i> Gắn</button>
                            </div>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </form>
                    @if ($worker->imageUsers->isEmpty())
                        <div class="text-muted">Chưa gắn tài khoản nào.</div>
                    @else
                        <table class="table table-sm mb-0">
                            <tbody>
                            @foreach ($worker->imageUsers as $user)
                                <tr>
                                    <td><b>{{ $user->name }}</b> <span class="text-muted">{{ $user->email }}</span></td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('admin.workers.users.detach', [$worker, $user]) }}" class="d-inline"
                                              data-confirm="Bỏ {{ $user->email }} khỏi worker &quot;{{ $worker->name }}&quot;? Job ảnh của tài khoản này sẽ không giao cho worker này nữa.">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-xs btn-outline-danger"><i class="fas fa-times"></i> Bỏ</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.workers.rename', $worker) }}" class="mb-3">
                        @csrf @method('PATCH')
                        <label for="worker-name" class="mb-1">Tên worker</label>
                        <div class="input-group">
                            <input type="text" id="worker-name" name="name" value="{{ old('name', $worker->name) }}" maxlength="100"
                                   class="form-control @error('name') is-invalid @enderror" required>
                            <div class="input-group-append">
                                <button class="btn btn-primary"><i class="fas fa-save"></i> Lưu</button>
                            </div>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </form>
                    <dl class="mb-0">
                        <dt>Tài khoản đăng bài</dt>
                        <dd>
                            @if ($account = $worker->publishAccount)
                                <i class="fab fa-{{ $account->platform->value }}"></i> {{ $account->platform->label() }}
                                · <a href="{{ route('admin.publish-accounts.edit', $account) }}">{{ $account->name }}</a>
                            @else
                                <span class="text-muted">Chưa gắn.</span>
                                <a href="{{ route('admin.publish-accounts.create') }}">Gắn với tài khoản đăng bài</a>
                            @endif
                        </dd>
                        <dt>Hoạt động gần nhất</dt>
                        <dd>{{ $worker->last_seen_at ? $worker->last_seen_at->format('d/m/Y H:i').' ('.$worker->last_seen_at->diffForHumans().')' : 'Chưa kết nối' }}</dd>
                        <dt>Tạo lúc</dt>
                        <dd class="mb-0">{{ $worker->created_at?->format('d/m/Y H:i') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection

@if ($token !== null)
    @push('scripts')
        <script>
            (function () {
                var input = document.getElementById('worker-token');
                document.getElementById('toggle-token').addEventListener('click', function () {
                    var hidden = input.type === 'password';
                    input.type = hidden ? 'text' : 'password';
                    this.innerHTML = hidden ? '<i class="fas fa-eye-slash"></i> Ẩn' : '<i class="fas fa-eye"></i> Hiện';
                });
                document.getElementById('copy-token').addEventListener('click', function () {
                    var button = this;
                    var done = function () {
                        button.innerHTML = '<i class="fas fa-check"></i> Đã copy';
                        setTimeout(function () { button.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 2000);
                    };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(input.value).then(done);
                    } else {
                        // http (không có clipboard API): chọn chữ rồi copy
                        var type = input.type;
                        input.type = 'text';
                        input.select();
                        document.execCommand('copy');
                        input.type = type;
                        done();
                    }
                });
            })();
        </script>
    @endpush
@endif
