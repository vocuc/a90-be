@extends('admin.layouts.app')
@section('title', 'Cấu hình hệ thống')
@section('breadcrumb')<li class="breadcrumb-item active">Cấu hình</li>@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <div class="card card-primary">
                <form method="POST" action="{{ route('admin.settings.update') }}" data-confirm="Lưu cấu hình? Thay đổi có hiệu lực ngay với các lượt tạo ảnh mới.">
                    @csrf @method('PUT')
                    <div class="card-body">
                        @foreach ($fields as $field)
                            <div class="form-group row">
                                <label for="{{ $field['name'] }}" class="col-sm-5 col-form-label">{{ $field['label'] }}</label>
                                <div class="col-sm-7">
                                    <input type="number" step="any" id="{{ $field['name'] }}" name="{{ $field['name'] }}"
                                           value="{{ old($field['name'], $field['value']) }}"
                                           class="form-control @error($field['name']) is-invalid @enderror" required>
                                    <small class="form-text text-muted">{{ $field['help'] }}</small>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="card-footer"><button class="btn btn-primary"><i class="fas fa-save"></i> Lưu cấu hình</button></div>
                </form>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="callout callout-info">
                <h5>Lưu ý</h5>
                <p class="mb-1">Transaction cũ không bị ảnh hưởng vì đã lưu snapshot % Creator nhận và giá tại thời điểm tạo.</p>
                <p class="mb-0">Mọi thay đổi được ghi vào <a href="{{ route('admin.audit-logs.index', ['action' => 'admin.settings']) }}">Nhật ký hệ thống</a>.</p>
            </div>
        </div>
    </div>
@endsection
