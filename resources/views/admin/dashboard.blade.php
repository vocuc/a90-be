@extends('admin.layouts.app')
@section('title', 'Dashboard')

@section('content')
    <div class="row">
        <div class="col-lg-3 col-6">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>@credit($stats['users'])</h3>
                    <p>Người dùng <small>(+{{ $stats['users_today'] }} hôm nay)</small></p>
                </div>
                <div class="icon"><i class="fas fa-users"></i></div>
                <a href="{{ route('admin.users.index') }}" class="small-box-footer">Xem <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>@credit($stats['creatives_published'])</h3>
                    <p>Creative đang bán · {{ $stats['creators'] }} Creator</p>
                </div>
                <div class="icon"><i class="fas fa-magic"></i></div>
                <a href="{{ route('admin.creatives.index', ['status' => 'published']) }}" class="small-box-footer">Xem <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-warning">
                <div class="inner">
                    <h3>@credit($stats['images_today'])</h3>
                    <p>Ảnh tạo hôm nay · {{ $stats['images_failed_today'] }} lỗi</p>
                </div>
                <div class="icon"><i class="fas fa-images"></i></div>
                <a href="{{ route('admin.generations.index') }}" class="small-box-footer">Xem <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-danger">
                <div class="inner">
                    <h3>@credit($stats['fee_today'])</h3>
                    <p>Phí nền tảng hôm nay (Credit)</p>
                </div>
                <div class="icon"><i class="fas fa-coins"></i></div>
                <a href="{{ route('admin.transactions.index', ['status' => 'completed']) }}" class="small-box-footer">Xem <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3 col-sm-6">
            <div class="info-box">
                <span class="info-box-icon bg-primary"><i class="fas fa-shopping-cart"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Doanh số tháng này</span>
                    <span class="info-box-number">@credit($stats['gmv_month']) <small>Credit</small></span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box">
                <span class="info-box-icon bg-success"><i class="fas fa-percent"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Phí nền tảng tháng này</span>
                    <span class="info-box-number">@credit($stats['fee_month']) <small>Credit</small></span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box">
                <span class="info-box-icon bg-info"><i class="fas fa-plus-circle"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Credit admin đã cộng (tháng)</span>
                    <span class="info-box-number">@credit($stats['topup_month'])</span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box">
                <span class="info-box-icon bg-secondary"><i class="fas fa-cogs"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Ảnh chờ worker / đang xử lý</span>
                    <span class="info-box-number">{{ $stats['queued'] }} / {{ $stats['processing'] }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Lượt tạo ảnh gần đây</h3></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>#</th><th>Người dùng</th><th>Creative</th><th>Loại</th><th>Ảnh</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
                        <tbody>
                        @forelse ($recentGenerations as $generation)
                            <tr>
                                <td><a href="{{ route('admin.generations.show', $generation) }}">{{ $generation->id }}</a></td>
                                <td>{{ $generation->user->email }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($generation->creative->title, 30) }}</td>
                                <td><x-admin.status :value="$generation->type" /></td>
                                <td>{{ $generation->success_count }}/{{ $generation->quantity }}</td>
                                <td><x-admin.status :value="$generation->status" /></td>
                                <td class="text-muted">{{ $generation->created_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">Chưa có lượt tạo ảnh.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Creative được dùng nhiều nhất</h3></div>
                <ul class="list-group list-group-flush">
                    @forelse ($topCreatives as $creative)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <a href="{{ route('admin.creatives.show', $creative) }}">{{ \Illuminate\Support\Str::limit($creative->title, 32) }}</a>
                                <div class="text-muted small">{{ '@'.($creative->creator->creatorProfile?->username ?? '—') }}</div>
                            </div>
                            <span class="badge badge-primary badge-pill">@credit($creative->usage_count)</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Chưa có dữ liệu.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection
