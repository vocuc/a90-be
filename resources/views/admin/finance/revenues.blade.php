@extends('admin.layouts.app')
@section('title', 'Doanh thu Creator')
@section('breadcrumb')<li class="breadcrumb-item active">Doanh thu Creator</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <form class="form-inline filter-bar" method="GET">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm mr-2 mb-1" placeholder="Username hoặc tên shop">
                <select name="status" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả trạng thái</option>
                    @foreach (\App\Enums\RevenueStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->value }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> Lọc</button>
            </form>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>#</th><th>Creator</th><th>Transaction</th><th>Creative</th><th class="text-right">Số tiền</th><th>Trạng thái</th><th>Khả dụng từ</th><th>Ghi nhận</th></tr></thead>
                <tbody>
                @forelse ($revenues as $revenue)
                    <tr>
                        <td>{{ $revenue->id }}</td>
                        <td>{{ '@'.($revenue->creator->creatorProfile?->username ?? $revenue->creator_id) }}</td>
                        <td><a href="{{ route('admin.generations.show', $revenue->transaction->generation_id) }}"><code>{{ $revenue->transaction->code }}</code></a></td>
                        <td>{{ \Illuminate\Support\Str::limit($revenue->transaction->creative->title, 30) }}</td>
                        <td class="text-right">@credit($revenue->amount)</td>
                        <td><x-admin.status :value="$revenue->status" /></td>
                        <td class="text-muted">{{ $revenue->available_at->format('d/m/Y H:i') }}</td>
                        <td class="text-muted">{{ $revenue->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-3">Chưa có doanh thu.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">{{ $revenues->links() }}</div>
    </div>
@endsection
