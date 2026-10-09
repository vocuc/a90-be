@php
    $editing = $banner->exists;
    $imageUrl = $editing ? app(\App\Services\MediaStorage::class)->url($banner->image_path) : null;
@endphp
@extends('admin.layouts.app')
@section('title', $editing ? 'Sửa banner' : 'Thêm banner')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.banners.index') }}">Banner</a></li>
    <li class="breadcrumb-item active">{{ $editing ? ($banner->title ?: '#'.$banner->id) : 'Thêm mới' }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form method="POST" enctype="multipart/form-data"
                  action="{{ $editing ? route('admin.banners.update', $banner) : route('admin.banners.store') }}" class="card">
                @csrf
                @if ($editing) @method('PUT') @endif
                <div class="card-body">
                    <div class="form-group">
                        <label for="image">Ảnh banner @unless ($editing)<span class="text-danger">*</span>@endunless</label>
                        <div class="mb-2">
                            <img id="image-preview" src="{{ $imageUrl }}" alt="" class="rounded border w-100"
                                 style="max-height:240px;object-fit:contain;{{ $imageUrl ? '' : 'display:none' }}">
                        </div>
                        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" @required(! $editing)
                               class="form-control-file @error('image') is-invalid @enderror">
                        @error('image')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            JPG, PNG hoặc WEBP, tối đa 5MB. Nên dùng ảnh ngang tỉ lệ <b>2:1</b> (vd: 1200×600).
                            @if ($editing) Để trống nếu giữ ảnh hiện tại. @endif
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="link_url">Link khi bấm vào <span class="text-danger">*</span></label>
                        <input type="text" id="link_url" name="link_url" maxlength="500" required
                               value="{{ old('link_url', $banner->link_url) }}" placeholder="vd: /explore?category=thoi-trang hoặc https://..."
                               class="form-control @error('link_url') is-invalid @enderror">
                        @error('link_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Đường dẫn trong app bắt đầu bằng <code>/</code> (mở ngay trong app),
                            hoặc link ngoài <code>https://...</code> (mở tab mới).
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="title">Tên banner</label>
                        <input type="text" id="title" name="title" maxlength="100"
                               value="{{ old('title', $banner->title) }}" placeholder="Không bắt buộc, dùng để phân biệt và làm mô tả ảnh"
                               class="form-control @error('title') is-invalid @enderror">
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-row">
                        <div class="form-group col-sm-4">
                            <label for="sort_order">Thứ tự hiển thị</label>
                            <input type="number" id="sort_order" name="sort_order" min="0" max="65535" required
                                   value="{{ old('sort_order', $banner->sort_order) }}"
                                   class="form-control @error('sort_order') is-invalid @enderror">
                            @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="form-text text-muted">Số nhỏ hiển thị trước.</small>
                        </div>
                        <div class="form-group col-sm-8 d-flex align-items-center">
                            <input type="hidden" name="is_active" value="0">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1"
                                       @checked(old('is_active', $banner->is_active))>
                                <label class="custom-control-label" for="is_active">Hiển thị trên app</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-save"></i> {{ $editing ? 'Lưu thay đổi' : 'Thêm banner' }}</button>
                    <a href="{{ route('admin.banners.index') }}" class="btn btn-link">Hủy</a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var input = document.getElementById('image');
            var preview = document.getElementById('image-preview');
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) return;
                preview.src = URL.createObjectURL(file);
                preview.style.display = '';
            });
        })();
    </script>
@endpush
