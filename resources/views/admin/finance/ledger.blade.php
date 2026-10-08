@extends('admin.layouts.app')
@section('title', 'Sổ cái Credit')
@section('breadcrumb')<li class="breadcrumb-item active">Sổ cái Credit</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <form class="form-inline filter-bar" method="GET">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm mr-2 mb-1" placeholder="Email hoặc ID người dùng">
                <select name="type" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả loại</option>
                    @foreach (\App\Enums\LedgerType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->value }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> Lọc</button>
            </form>
            <p class="text-muted small mb-0 mt-2">Sổ cái chỉ được thêm dòng, không sửa hay xóa. Tổng các dòng của một người dùng luôn bằng số dư ví.</p>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>#</th><th>Thời gian</th><th>Người dùng</th><th>Loại</th><th class="text-right">Số tiền</th><th class="text-right">Số dư sau</th><th>Tham chiếu</th><th>Ghi chú</th><th>Người thực hiện</th></tr></thead>
                <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td>{{ $entry->id }}</td>
                        <td class="text-muted">{{ $entry->created_at->format('d/m/Y H:i:s') }}</td>
                        <td><a href="{{ route('admin.users.show', $entry->user_id) }}">{{ $users[$entry->user_id]->email ?? '#'.$entry->user_id }}</a></td>
                        <td><code>{{ $entry->type->value }}</code></td>
                        <td class="text-right {{ $entry->amount < 0 ? 'text-danger' : 'text-success' }}">{{ $entry->amount > 0 ? '+' : '' }}@credit($entry->amount)</td>
                        <td class="text-right">@credit($entry->balance_after)</td>
                        <td class="small text-muted">{{ $entry->reference_type }} {{ $entry->reference_id ? '#'.$entry->reference_id : '' }}</td>
                        <td>{{ $entry->note }}</td>
                        <td class="small">{{ $entry->created_by ? ($users[$entry->created_by]->email ?? '#'.$entry->created_by) : 'Hệ thống' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-3">Chưa có biến động.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">{{ $entries->links() }}</div>
    </div>
@endsection
