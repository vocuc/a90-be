@extends('admin.layouts.app')
@section('title', $user->name)
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Người dùng</a></li>
    <li class="breadcrumb-item active">#{{ $user->id }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-4">
            <div class="card card-primary card-outline">
                <div class="card-body box-profile">
                    <h3 class="profile-username text-center">{{ $user->name }}</h3>
                    <p class="text-muted text-center mb-2">{{ $user->email }}</p>
                    <p class="text-center">
                        <x-admin.status :value="$user->status" />
                        @if ($user->isAdmin()) <span class="badge badge-danger">Admin</span> @endif
                    </p>
                    <ul class="list-group list-group-unbordered mb-3">
                        <li class="list-group-item"><b>Số dư Credit</b> <span class="float-right h5 mb-0">@credit($user->creditWallet?->balance ?? 0)</span></li>
                        <li class="list-group-item"><b>Ngày tạo</b> <span class="float-right">{{ $user->created_at->format('d/m/Y H:i') }}</span></li>
                        @if ($user->creatorProfile)
                            <li class="list-group-item"><b>Creator</b> <span class="float-right">{{ '@'.$user->creatorProfile->username }} @if ($user->creatorProfile->verified)<i class="fas fa-check-circle text-primary"></i>@endif</span></li>
                            <li class="list-group-item"><b>Ví Creator (pending / available)</b>
                                <span class="float-right">@credit($user->creatorWallet?->pending_balance ?? 0) / @credit($user->creatorWallet?->available_balance ?? 0)</span></li>
                        @endif
                    </ul>
                    @unless ($user->isAdmin())
                        <form method="POST" action="{{ route('admin.users.toggle-ban', $user) }}"
                              data-confirm="{{ $user->isActive() ? 'Khóa tài khoản này? Người dùng sẽ bị đăng xuất khỏi mọi thiết bị.' : 'Mở khóa tài khoản này?' }}">
                            @csrf
                            <button class="btn btn-block {{ $user->isActive() ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                <i class="fas {{ $user->isActive() ? 'fa-lock' : 'fa-unlock' }}"></i>
                                {{ $user->isActive() ? 'Khóa tài khoản' : 'Mở khóa tài khoản' }}
                            </button>
                        </form>
                    @endunless
                </div>
            </div>

            <div class="card card-success">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-coins"></i> Điều chỉnh Credit</h3></div>
                <form method="POST" action="{{ route('admin.users.credits', $user) }}" data-confirm="Xác nhận điều chỉnh Credit cho {{ $user->email }}?">
                    @csrf
                    {{-- Mỗi lần mở form có một khóa riêng: bấm gửi 2 lần cũng chỉ ghi 1 lần. --}}
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <div class="card-body">
                        <div class="form-group">
                            <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                                <label class="btn btn-outline-success {{ old('direction', 'add') === 'add' ? 'active' : '' }}">
                                    <input type="radio" name="direction" value="add" @checked(old('direction', 'add') === 'add')> Cộng
                                </label>
                                <label class="btn btn-outline-danger {{ old('direction') === 'subtract' ? 'active' : '' }}">
                                    <input type="radio" name="direction" value="subtract" @checked(old('direction') === 'subtract')> Trừ
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Số Credit <small class="text-muted">(1 Credit = 1 VND)</small></label>
                            <input type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" class="form-control" required>
                        </div>
                        <div class="form-group mb-0">
                            <label>Ghi chú</label>
                            <input type="text" name="note" value="{{ old('note') }}" class="form-control" maxlength="255" placeholder="VD: Chuyển khoản ngày 08/10, mã GD ..." required>
                        </div>
                    </div>
                    <div class="card-footer"><button class="btn btn-success btn-block">Xác nhận</button></div>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Biến động Credit gần đây</h3>
                    <div class="card-tools"><a href="{{ route('admin.ledger.index', ['q' => $user->id]) }}" class="btn btn-tool">Xem tất cả</a></div>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Thời gian</th><th>Loại</th><th class="text-right">Số tiền</th><th class="text-right">Số dư sau</th><th>Ghi chú</th></tr></thead>
                        <tbody>
                        @forelse ($ledger as $entry)
                            <tr>
                                <td class="text-muted">{{ $entry->created_at->format('d/m/Y H:i') }}</td>
                                <td><code>{{ $entry->type->value }}</code></td>
                                <td class="text-right {{ $entry->amount < 0 ? 'text-danger' : 'text-success' }}">{{ $entry->amount > 0 ? '+' : '' }}@credit($entry->amount)</td>
                                <td class="text-right">@credit($entry->balance_after)</td>
                                <td>{{ $entry->note }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">Chưa có biến động.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Lượt tạo ảnh gần đây</h3></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>#</th><th>Creative</th><th>Loại</th><th>Ảnh</th><th class="text-right">Tổng tiền</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
                        <tbody>
                        @forelse ($generations as $generation)
                            <tr>
                                <td><a href="{{ route('admin.generations.show', $generation) }}">{{ $generation->id }}</a></td>
                                <td>{{ \Illuminate\Support\Str::limit($generation->creative->title, 30) }}</td>
                                <td><x-admin.status :value="$generation->type" /></td>
                                <td>{{ $generation->success_count }}/{{ $generation->quantity }}</td>
                                <td class="text-right">@credit($generation->price_per_image * $generation->quantity)</td>
                                <td><x-admin.status :value="$generation->status" /></td>
                                <td class="text-muted">{{ $generation->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">Chưa có lượt tạo ảnh.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
