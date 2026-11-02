@extends('admin.layouts.app')
@section('title', 'Thêm worker')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.workers.index') }}">Worker</a></li>
    <li class="breadcrumb-item active">Thêm mới</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-6">
            <form method="POST" action="{{ route('admin.workers.store') }}" class="card">
                @csrf
                <div class="card-body">
                    <div class="form-group">
                        <label for="name">Tên worker <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" maxlength="100" required autofocus
                               value="{{ old('name') }}" placeholder="vd: phone1-tiktok"
                               class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Đặt theo máy + việc để dễ nhận ra, vd <code>phone1-tiktok</code>, <code>phone1-facebook</code>, <code>pc-tao-anh</code>.
                            Tạo xong sẽ hiện token để dán vào <code>.env</code> của máy.
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Loại worker <span class="text-danger">*</span></label>
                        @foreach (\App\Models\Worker::TYPES as $type)
                            <div class="custom-control custom-radio">
                                <input type="radio" id="type-{{ $type }}" name="type" value="{{ $type }}" class="custom-control-input"
                                       @checked(old('type', \App\Models\Worker::TYPE_IMAGE) === $type)>
                                <label class="custom-control-label font-weight-normal" for="type-{{ $type }}">
                                    <b>{{ \App\Models\Worker::typeLabel($type) }}</b>
                                    <span class="text-muted">
                                        {{ $type === \App\Models\Worker::TYPE_IMAGE
                                            ? '— nhận job tạo ảnh của các tài khoản được gắn'
                                            : '— shop được gắn chọn worker ở trang bài đăng để đăng bài ảnh / video' }}
                                    </span>
                                </label>
                            </div>
                        @endforeach
                        @error('type')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group mb-0">
                        <label for="email">Gắn cho tài khoản A51</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="Email tài khoản A51"
                               class="form-control @error('email') is-invalid @enderror">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">Không bắt buộc. Gắn thêm / bỏ tài khoản ở trang chi tiết worker.</small>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-plus"></i> Tạo worker</button>
                    <a href="{{ route('admin.workers.index') }}" class="btn btn-link">Hủy</a>
                </div>
            </form>
        </div>
    </div>
@endsection
