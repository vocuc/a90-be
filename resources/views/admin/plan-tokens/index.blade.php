@extends('admin.layouts.app')
@section('title', 'Token lập kế hoạch')
@section('breadcrumb')<li class="breadcrumb-item active">Token lập kế hoạch</li>@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            @if ($plain)
                <div class="callout callout-warning">
                    <h5>Token mới (chỉ hiện 1 lần)</h5>
                    <p class="mb-1">Dán vào <code>D:\FlowKit\.claude\skills\lap-ke-hoach\.env</code> dòng <code>A51_PLAN_TOKEN=</code></p>
                    <input type="text" class="form-control" value="{{ $plain }}" readonly onclick="this.select()">
                </div>
            @endif

            <div class="card card-primary">
                <div class="card-header"><h3 class="card-title">Token của bạn</h3></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Tên</th><th>Tạo lúc</th><th>Dùng gần nhất</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($tokens as $token)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::after($token->name, 'plan:') }}</td>
                                <td>{{ $token->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $token->last_used_at?->format('d/m/Y H:i') ?? 'Chưa dùng' }}</td>
                                <td class="text-right">
                                    <form method="POST" action="{{ route('admin.plan-tokens.destroy', $token->id) }}" data-confirm="Thu hồi token này? Lệnh /lap-ke-hoach dùng token này sẽ ngừng chạy.">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-xs btn-danger"><i class="fas fa-ban"></i> Thu hồi</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center py-3">Chưa có token.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <form method="POST" action="{{ route('admin.plan-tokens.store') }}" class="card-footer form-inline">
                    @csrf
                    <input type="text" name="name" class="form-control mr-2 @error('name') is-invalid @enderror" placeholder="Tên gợi nhớ, vd may-quan" required maxlength="60">
                    <button class="btn btn-primary"><i class="fas fa-key"></i> Tạo token</button>
                </form>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="callout callout-info">
                <h5>Dùng để làm gì?</h5>
                <p class="mb-0">Lệnh <code>/lap-ke-hoach &lt;id-kênh&gt;</code> trong Claude Code dùng token này để đọc dữ liệu kênh và gửi kế hoạch
                    cho <b>mọi shop</b>. Token thuộc tài khoản admin của bạn, chỉ dùng được cho API lập kế hoạch.</p>
            </div>
        </div>
    </div>
@endsection
