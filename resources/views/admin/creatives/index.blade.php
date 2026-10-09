@extends('admin.layouts.app')
@section('title', 'AI Creative')
@section('breadcrumb')<li class="breadcrumb-item active">AI Creative</li>@endsection

@inject('media', 'App\Services\MediaStorage')

@section('content')
    <div class="card">
        <div class="card-header">
            <form class="form-inline filter-bar" method="GET">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm mr-2 mb-1" placeholder="Tên, slug hoặc ID">
                <select name="status" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả trạng thái</option>
                    @foreach (\App\Enums\CreativeStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->value }}</option>
                    @endforeach
                </select>
                <select name="hidden" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Ẩn / hiện</option>
                    <option value="1" @selected(($filters['hidden'] ?? '') === '1')>Đang bị ẩn</option>
                    <option value="0" @selected(($filters['hidden'] ?? '') === '0')>Đang hiện</option>
                </select>
                <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> Lọc</button>
            </form>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th></th><th>Creative</th><th>Creator</th><th>Danh mục</th><th>Model</th><th class="text-right">Giá / ảnh</th><th class="text-right">Lượt dùng</th><th>Trạng thái</th><th></th></tr></thead>
                <tbody>
                @forelse ($creatives as $creative)
                    <tr>
                        <td><img src="{{ $media->url($creative->images->first()?->path) }}" class="thumb" alt="" onerror="this.style.visibility='hidden'"></td>
                        <td>
                            <a href="{{ route('admin.creatives.show', $creative) }}"><b>{{ \Illuminate\Support\Str::limit($creative->title, 40) }}</b></a>
                            <div class="text-muted small">#{{ $creative->id }} · {{ $creative->slug }}</div>
                        </td>
                        <td>{{ '@'.($creative->creator->creatorProfile?->username ?? $creative->creator->email) }}</td>
                        <td>{{ $creative->category?->name ?? '—' }}</td>
                        <td class="small">{{ $creative->model->name }}</td>
                        <td class="text-right">@credit($creative->price_per_image)</td>
                        <td class="text-right">@credit($creative->usage_count)</td>
                        <td>
                            <x-admin.status :value="$creative->status" />
                            @if ($creative->hidden_at)<span class="badge badge-danger"><i class="fas fa-eye-slash"></i> Bị ẩn</span>@endif
                        </td>
                        <td class="text-right"><a href="{{ route('admin.creatives.show', $creative) }}" class="btn btn-xs btn-outline-primary">Chi tiết</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-3">Không có AI Creative.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">{{ $creatives->links() }}</div>
    </div>
@endsection
