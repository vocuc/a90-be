@php
    $editing = $account->exists;
    $platform = old('platform', $account->platform?->value);
@endphp
@extends('admin.layouts.app')
@section('title', $editing ? 'Sửa tài khoản đăng bài' : 'Thêm tài khoản đăng bài')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.publish-accounts.index') }}">Tài khoản đăng bài</a></li>
    <li class="breadcrumb-item active">{{ $editing ? $account->name : 'Thêm mới' }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ $editing ? route('admin.publish-accounts.update', $account) : route('admin.publish-accounts.store') }}" class="card">
                @csrf
                @if ($editing) @method('PUT') @endif
                <div class="card-body">
                    <div class="form-group">
                        <label for="platform">Nền tảng <span class="text-danger">*</span></label>
                        <select id="platform" name="platform" required class="form-control @error('platform') is-invalid @enderror">
                            @foreach (\App\Enums\SocialPlatform::cases() as $option)
                                <option value="{{ $option->value }}" @selected($platform === $option->value)>{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        @error('platform')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">Worker gắn với tài khoản này chỉ nhận bài đăng lên nền tảng đã chọn.</small>
                    </div>

                    <div class="form-group">
                        <label for="name">Tên gợi nhớ <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" maxlength="100" required
                               value="{{ old('name', $account->name) }}" placeholder="vd: A51 Official"
                               class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">@</span></div>
                            <input type="text" id="username" name="username" maxlength="100"
                                   value="{{ old('username', $account->username) }}" placeholder="a51.vn"
                                   class="form-control @error('username') is-invalid @enderror">
                            @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <small class="form-text text-muted">Worker kiểm tra đang đăng nhập đúng tài khoản này (nếu đọc được), nhân viên biết vào tài khoản nào.</small>
                    </div>

                    <div class="form-group">
                        <label for="target_url">Link nơi đăng bài <span class="text-muted small">(bắt buộc với Facebook)</span></label>
                        <input type="url" id="target_url" name="target_url" maxlength="500"
                               value="{{ old('target_url', $account->target_url) }}" placeholder="vd: https://www.facebook.com/groups/123456"
                               class="form-control @error('target_url') is-invalid @enderror">
                        @error('target_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">Facebook: link trang, nhóm hoặc trang cá nhân worker sẽ đăng bài vào.</small>
                    </div>

                    <div class="form-group">
                        <label for="user_email">Tài khoản A51 (email) <span class="text-danger">*</span></label>
                        <input type="email" id="user_email" name="user_email" required
                               value="{{ old('user_email', $account->user?->email) }}" placeholder="vd: khachhang@gmail.com"
                               class="form-control @error('user_email') is-invalid @enderror">
                        @error('user_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">Worker chỉ nhận bài của tài khoản A51 này. Mỗi tài khoản A51 có tối đa 1 tài khoản mỗi nền tảng.</small>
                    </div>

                    <div class="form-group">
                        <label for="worker_id">Worker <span class="text-danger">*</span></label>
                        <select id="worker_id" name="worker_id" required class="form-control @error('worker_id') is-invalid @enderror">
                            <option value="">— Chọn worker —</option>
                            @foreach ($workers as $worker)
                                @php
                                    $taken = $worker->publishAccount && $worker->publishAccount->id !== $account->id;
                                @endphp
                                <option value="{{ $worker->id }}" @selected((string) old('worker_id', $account->worker_id) === (string) $worker->id) @disabled($taken)>
                                    {{ $worker->name }}{{ $taken ? ' (đang gắn với '.$worker->publishAccount->platform->label().' '.$worker->publishAccount->name.')' : '' }}{{ $worker->is_active ? '' : ' (đã tắt)' }}
                                </option>
                            @endforeach
                        </select>
                        @error('worker_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Máy chạy worker phải đăng nhập sẵn tài khoản này (trình duyệt hoặc app trên điện thoại).
                            Chưa có worker: <a href="{{ route('admin.workers.create') }}" target="_blank">tạo worker</a>
                            (lấy token ở trang Worker) rồi tải lại trang này.
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
                    <a href="{{ route('admin.publish-accounts.index') }}" class="btn btn-link">Hủy</a>
                </div>
            </form>
        </div>
        @if ($editing)
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Bài đã đăng gần đây</h3></div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                            @forelse ($recentJobs as $job)
                                <tr>
                                    <td>
                                        <b>#{{ $job->social_post_id }}</b> {{ \Illuminate\Support\Str::limit($job->post?->title ?: 'Bài đăng ảnh', 50) }}
                                        <div class="small text-muted">
                                            {{ $job->post?->user?->email }}
                                            @if ($job->post_url) · <a href="{{ $job->post_url }}" target="_blank" rel="noopener">Xem bài</a>@endif
                                        </div>
                                    </td>
                                    <td class="text-right small text-muted text-nowrap">{{ $job->finished_at?->format('d/m/Y H:i') }}</td>
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
