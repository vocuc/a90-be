@extends('admin.layouts.app')
@section('title', 'Banner')
@section('breadcrumb')<li class="breadcrumb-item active">Banner</li>@endsection

@section('content')
    @php($media = app(\App\Services\MediaStorage::class))
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <p class="text-muted small mb-0">
                Banner hiển thị ở trang chủ app theo <b>thứ tự</b> tăng dần, bấm vào mở link đã nhập. Banner <b>tắt</b> bị ẩn khỏi app.
            </p>
            <a href="{{ route('admin.banners.create') }}" class="btn btn-sm btn-primary ml-auto">
                <i class="fas fa-plus"></i> Thêm banner
            </a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                <tr>
                    <th class="text-center" style="width:70px">Thứ tự</th>
                    <th style="width:200px">Ảnh</th>
                    <th>Tên</th>
                    <th>Link</th>
                    <th class="text-center">Trạng thái</th>
                    <th class="text-right" style="width:230px"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($banners as $banner)
                    <tr class="{{ $banner->is_active ? '' : 'text-muted' }}">
                        <td class="text-center align-middle">{{ $banner->sort_order }}</td>
                        <td class="align-middle">
                            <img src="{{ $media->url($banner->image_path) }}" alt="" class="rounded border"
                                 style="width:180px;max-height:90px;object-fit:cover">
                        </td>
                        <td class="align-middle"><b>{{ $banner->title ?: '—' }}</b></td>
                        <td class="align-middle text-break"><code>{{ $banner->link_url }}</code></td>
                        <td class="text-center align-middle">
                            <span class="badge {{ $banner->is_active ? 'badge-success' : 'badge-secondary' }}">
                                {{ $banner->is_active ? 'Đang bật' : 'Đã tắt' }}
                            </span>
                        </td>
                        <td class="text-right text-nowrap align-middle">
                            <a href="{{ route('admin.banners.edit', $banner) }}" class="btn btn-xs btn-outline-primary">
                                <i class="fas fa-edit"></i> Sửa
                            </a>
                            <form method="POST" action="{{ route('admin.banners.toggle', $banner) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-xs {{ $banner->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                    <i class="fas {{ $banner->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                    {{ $banner->is_active ? 'Tắt' : 'Bật' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.banners.destroy', $banner) }}" class="d-inline"
                                  data-confirm="Xóa banner này? Thao tác này không hoàn tác được.">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger"><i class="fas fa-trash"></i> Xóa</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-3">Chưa có banner.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
