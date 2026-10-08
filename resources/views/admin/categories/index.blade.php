@extends('admin.layouts.app')
@section('title', 'Danh mục')
@section('breadcrumb')<li class="breadcrumb-item active">Danh mục</li>@endsection

@include('admin.categories.partials.icon-font')

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <p class="text-muted small mb-0">
                Danh mục hiển thị trên app theo <b>thứ tự</b> tăng dần. Danh mục <b>tắt</b> bị ẩn khỏi app và Creator không chọn được khi tạo Creative.
            </p>
            <a href="{{ route('admin.categories.create') }}" class="btn btn-sm btn-primary ml-auto">
                <i class="fas fa-plus"></i> Thêm danh mục
            </a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                <tr>
                    <th class="text-center" style="width:70px">Thứ tự</th>
                    <th class="text-center" style="width:60px">Icon</th>
                    <th>Tên</th>
                    <th>Slug</th>
                    <th class="text-center">Creative</th>
                    <th class="text-center">Trạng thái</th>
                    <th class="text-right" style="width:230px"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($categories as $category)
                    <tr class="{{ $category->is_active ? '' : 'text-muted' }}">
                        <td class="text-center">{{ $category->sort_order }}</td>
                        <td class="text-center">
                            @if ($category->icon)
                                <span class="material-symbols-outlined category-icon" title="{{ $category->icon }}">{{ $category->icon }}</span>
                            @else
                                <span class="text-muted small" title="App dùng icon mặc định">—</span>
                            @endif
                        </td>
                        <td><b>{{ $category->name }}</b></td>
                        <td><code>{{ $category->slug }}</code></td>
                        <td class="text-center">{{ $category->creatives_count }}</td>
                        <td class="text-center">
                            <span class="badge {{ $category->is_active ? 'badge-success' : 'badge-secondary' }}">
                                {{ $category->is_active ? 'Đang bật' : 'Đã tắt' }}
                            </span>
                        </td>
                        <td class="text-right text-nowrap">
                            <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-xs btn-outline-primary">
                                <i class="fas fa-edit"></i> Sửa
                            </a>
                            <form method="POST" action="{{ route('admin.categories.toggle', $category) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-xs {{ $category->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                    <i class="fas {{ $category->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                    {{ $category->is_active ? 'Tắt' : 'Bật' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="d-inline"
                                  data-confirm="Xóa danh mục &quot;{{ $category->name }}&quot;? Thao tác này không hoàn tác được.">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger" @disabled($category->creatives_count > 0)
                                        title="{{ $category->creatives_count > 0 ? 'Đang có Creative, hãy tắt thay vì xóa' : 'Xóa danh mục' }}">
                                    <i class="fas fa-trash"></i> Xóa
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">Chưa có danh mục.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
