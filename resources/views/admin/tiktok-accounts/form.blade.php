@php
    $editing = $account->exists;
@endphp
@extends('admin.layouts.app')
@section('title', $editing ? 'Sửa tài khoản TikTok' : 'Thêm tài khoản TikTok')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.tiktok-accounts.index') }}">Tài khoản TikTok</a></li>
    <li class="breadcrumb-item active">{{ $editing ? $account->name : 'Thêm mới' }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ $editing ? route('admin.tiktok-accounts.update', $account) : route('admin.tiktok-accounts.store') }}" class="card">
                @csrf
                @if ($editing) @method('PUT') @endif
                <div class="card-body">
                    <div class="form-group">
                        <label for="name">Tên gợi nhớ <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" maxlength="100" required
                               value="{{ old('name', $account->name) }}" placeholder="vd: A51 Official"
                               class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label for="username">Username TikTok</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">@</span></div>
                            <input type="text" id="username" name="username" maxlength="100"
                                   value="{{ old('username', $account->username) }}" placeholder="a51.vn"
                                   class="form-control @error('username') is-invalid @enderror">
                            @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <small class="form-text text-muted">Để nhân viên biết vào tài khoản nào đăng các bài nháp.</small>
                    </div>

                    <div class="form-group">
                        <label for="worker_id">Worker giữ tài khoản</label>
                        <select id="worker_id" name="worker_id" class="form-control @error('worker_id') is-invalid @enderror">
                            <option value="">— Chưa gắn —</option>
                            @foreach ($workers as $worker)
                                @php
                                    $taken = $worker->tiktokAccount && $worker->tiktokAccount->id !== $account->id;
                                @endphp
                                <option value="{{ $worker->id }}" @selected((string) old('worker_id', $account->worker_id) === (string) $worker->id) @disabled($taken)>
                                    {{ $worker->name }}{{ $taken ? ' (đang giữ '.$worker->tiktokAccount->name.')' : '' }}{{ $worker->is_active ? '' : ' (đã tắt)' }}
                                </option>
                            @endforeach
                        </select>
                        @error('worker_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Máy chạy worker phải đăng nhập sẵn tài khoản này trên TikTok Studio. Tạo worker bằng
                            <code>php artisan workers:create &lt;tên&gt;</code>.
                        </small>
                    </div>

                    <div class="form-group mb-0">
                        <input type="hidden" name="is_active" value="0">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1"
                                   @checked(old('is_active', $account->is_active))>
                            <label class="custom-control-label" for="is_active">Đang bật (worker được nhận bài để đăng)</label>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-save"></i> {{ $editing ? 'Lưu thay đổi' : 'Thêm tài khoản' }}</button>
                    <a href="{{ route('admin.tiktok-accounts.index') }}" class="btn btn-link">Hủy</a>
                </div>
            </form>
        </div>
        @if ($editing)
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Bài đã lưu nháp gần đây</h3></div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                            @forelse ($drafts as $post)
                                <tr>
                                    <td>
                                        <b>#{{ $post->id }}</b> {{ \Illuminate\Support\Str::limit($post->title ?: 'Bài đăng ảnh', 50) }}
                                        <div class="small text-muted">{{ $post->user?->email }}</div>
                                    </td>
                                    <td class="text-right small text-muted text-nowrap">{{ $post->published_at?->format('d/m/Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-muted py-3">Chưa có bài nào.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
