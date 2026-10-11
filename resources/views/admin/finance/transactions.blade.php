@extends('admin.layouts.app')
@section('title', 'Transaction')
@section('breadcrumb')<li class="breadcrumb-item active">Transaction</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <form class="form-inline filter-bar" method="GET">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm mr-2 mb-1" placeholder="Mã TX hoặc email">
                <select name="status" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả trạng thái</option>
                    @foreach (\App\Enums\TransactionStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->value }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> Lọc</button>
            </form>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>Mã</th><th>Người mua</th><th>Creative</th><th>Model</th><th class="text-right">Số tiền</th><th class="text-right">Phí</th><th class="text-right">Creator nhận</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
                <tbody>
                @forelse ($transactions as $tx)
                    <tr>
                        <td><a href="{{ route('admin.generations.show', $tx->generation_id) }}"><code>{{ $tx->code }}</code></a></td>
                        <td>{{ $tx->user->email }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($tx->creative->title, 28) }}</td>
                        <td class="small">{{ $tx->model_code }}</td>
                        <td class="text-right">@credit($tx->amount)</td>
                        <td class="text-right">@credit($tx->platform_fee_amount)</td>
                        <td class="text-right">@credit($tx->creator_amount) <small class="text-muted">({{ (float) $tx->creator_share_percent }}%)</small></td>
                        <td>
                            <x-admin.status :value="$tx->status" />
                            @if ($tx->failure_reason)<small class="text-muted d-block">{{ $tx->failure_reason }}</small>@endif
                        </td>
                        <td class="text-muted">{{ $tx->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-3">Chưa có Transaction.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">{{ $transactions->links() }}</div>
    </div>
@endsection
