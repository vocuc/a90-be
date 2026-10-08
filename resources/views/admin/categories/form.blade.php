@php
    $editing = $category->exists;
    // Gợi ý nhanh các icon hay dùng cho danh mục sản phẩm
    $suggestedIcons = [
        'styler', 'checkroom', 'steps', 'shopping_bag', 'spa', 'diamond', 'watch', 'restaurant',
        'local_cafe', 'chair', 'bed', 'devices', 'smartphone', 'headphones', 'directions_car',
        'sports_soccer', 'toys', 'pets', 'child_care', 'menu_book', 'local_florist', 'category',
    ];
@endphp
@extends('admin.layouts.app')
@section('title', $editing ? 'Sửa danh mục' : 'Thêm danh mục')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.categories.index') }}">Danh mục</a></li>
    <li class="breadcrumb-item active">{{ $editing ? $category->name : 'Thêm mới' }}</li>
@endsection

@include('admin.categories.partials.icon-font')

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form method="POST" action="{{ $editing ? route('admin.categories.update', $category) : route('admin.categories.store') }}" class="card">
                @csrf
                @if ($editing) @method('PUT') @endif
                <div class="card-body">
                    <div class="form-group">
                        <label for="name">Tên danh mục <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" maxlength="100" required
                               value="{{ old('name', $category->name) }}"
                               class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label for="slug">Slug</label>
                        <input type="text" id="slug" name="slug" maxlength="120"
                               value="{{ old('slug', $category->slug) }}" placeholder="Để trống để tạo tự động từ tên"
                               class="form-control @error('slug') is-invalid @enderror">
                        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Dùng trong đường dẫn lọc trên app (<code>/explore?category=slug</code>).
                            @if ($editing)<b>Đổi slug sẽ làm hỏng các link cũ đã chia sẻ.</b>@endif
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="icon">Icon</label>
                        <div class="d-flex align-items-center">
                            <span class="icon-preview mr-3" title="Xem trước">
                                <span class="material-symbols-outlined" id="icon-preview">{{ old('icon', $category->icon) ?: 'category' }}</span>
                            </span>
                            <div class="flex-grow-1">
                                <input type="text" id="icon" name="icon" maxlength="50"
                                       value="{{ old('icon', $category->icon) }}" placeholder="vd: shopping_bag"
                                       class="form-control @error('icon') is-invalid @enderror">
                                @error('icon')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <small class="form-text text-muted">
                            Tên icon <b>Material Symbols</b>, tra tại
                            <a href="https://fonts.google.com/icons?icon.set=Material+Symbols" target="_blank" rel="noopener">fonts.google.com/icons</a>.
                            Để trống thì app dùng icon mặc định <code>category</code>.
                            Nếu khung xem trước hiện chữ thay vì hình, tên icon không tồn tại.
                        </small>
                        <div class="mt-2">
                            @foreach ($suggestedIcons as $name)
                                <button type="button" class="btn btn-light border icon-pick mb-1" data-icon="{{ $name }}" title="{{ $name }}">
                                    <span class="material-symbols-outlined">{{ $name }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-sm-4">
                            <label for="sort_order">Thứ tự hiển thị</label>
                            <input type="number" id="sort_order" name="sort_order" min="0" max="65535" required
                                   value="{{ old('sort_order', $category->sort_order) }}"
                                   class="form-control @error('sort_order') is-invalid @enderror">
                            @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="form-text text-muted">Số nhỏ hiển thị trước.</small>
                        </div>
                        <div class="form-group col-sm-8 d-flex align-items-center">
                            <input type="hidden" name="is_active" value="0">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1"
                                       @checked(old('is_active', $category->is_active))>
                                <label class="custom-control-label" for="is_active">Hiển thị trên app</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-save"></i> {{ $editing ? 'Lưu thay đổi' : 'Thêm danh mục' }}</button>
                    <a href="{{ route('admin.categories.index') }}" class="btn btn-link">Hủy</a>
                </div>
            </form>
        </div>

        @if ($editing)
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <p class="mb-1">Số Creative trong danh mục: <b>{{ $category->creatives_count }}</b></p>
                        <p class="text-muted small mb-0">
                            @if ($category->creatives_count > 0)
                                Danh mục đang có Creative nên không xóa được. Tắt danh mục để ẩn khỏi app.
                            @else
                                Danh mục chưa có Creative, có thể xóa ở trang danh sách.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var input = document.getElementById('icon');
            var preview = document.getElementById('icon-preview');
            var sync = function () { preview.textContent = input.value.trim().toLowerCase() || 'category'; };
            input.addEventListener('input', sync);
            document.querySelectorAll('.icon-pick').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    input.value = btn.getAttribute('data-icon');
                    sync();
                });
            });
        })();
    </script>
@endpush
