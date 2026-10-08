@extends('admin.layouts.app')
@section('title', 'Lượt tạo ảnh')
@section('breadcrumb')<li class="breadcrumb-item active">Lượt tạo ảnh</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <form class="form-inline filter-bar" method="GET">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm mr-2 mb-1" placeholder="ID, email, tên Creative">
                <select name="status" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả trạng thái</option>
                    @foreach (\App\Enums\GenerationStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->value }}</option>
                    @endforeach
                </select>
                <select name="type" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả loại</option>
                    @foreach (\App\Enums\GenerationType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->value }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> Lọc</button>
            </form>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>#</th><th>Người dùng</th><th>Creative</th><th>Loại</th><th>Tỉ lệ</th><th class="text-center">Thành công / lỗi / tổng</th><th class="text-right">Tổng tiền</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
                <tbody>
                @forelse ($generations as $generation)
                    <tr>
                        <td><a href="{{ route('admin.generations.show', $generation) }}">{{ $generation->id }}</a>
                            @if ($generation->parent_generation_id)<span class="badge badge-light" title="Retry của #{{ $generation->parent_generation_id }}"><i class="fas fa-redo"></i></span>@endif
                        </td>
                        <td><a href="{{ route('admin.users.show', $generation->user_id) }}">{{ $generation->user->email }}</a></td>
                        <td><a href="{{ route('admin.creatives.show', $generation->creative_id) }}">{{ \Illuminate\Support\Str::limit($generation->creative->title, 30) }}</a></td>
                        <td><x-admin.status :value="$generation->type" /></td>
                        <td>{{ $generation->aspect_ratio }}</td>
                        <td class="text-center"><span class="text-success">{{ $generation->success_count }}</span> / <span class="text-danger">{{ $generation->failed_count }}</span> / {{ $generation->quantity }}</td>
                        <td class="text-right">@credit($generation->price_per_image * $generation->quantity)</td>
                        <td><x-admin.status :value="$generation->status" /></td>
                        <td class="text-muted">{{ $generation->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-3">Chưa có lượt tạo ảnh.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">{{ $generations->links() }}</div>
    </div>
@endsection
