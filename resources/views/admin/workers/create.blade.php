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
                    <div class="form-group mb-0">
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
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-plus"></i> Tạo worker</button>
                    <a href="{{ route('admin.workers.index') }}" class="btn btn-link">Hủy</a>
                </div>
            </form>
        </div>
    </div>
@endsection
