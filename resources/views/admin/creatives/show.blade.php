@extends('admin.layouts.app')
@section('title', $creative->title)
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.creatives.index') }}">AI Creative</a></li>
    <li class="breadcrumb-item active">#{{ $creative->id }}</li>
@endsection

@inject('media', 'App\Services\MediaStorage')

@section('content')
    <div class="row">
        <div class="col-lg-4">
            <div class="card card-primary card-outline">
                <div class="card-body">
                    <p class="mb-2">
                        <x-admin.status :value="$creative->status" />
                        @if ($creative->hidden_at)<span class="badge badge-danger"><i class="fas fa-eye-slash"></i> Bị ẩn từ {{ $creative->hidden_at->format('d/m/Y H:i') }}</span>@endif
                        @if ($creative->pause_reason)<span class="badge badge-light">Lý do dừng: {{ $creative->pause_reason->value }}</span>@endif
                    </p>
                    <dl class="mb-0">
                        <dt>Creator</dt>
                        <dd><a href="{{ route('admin.users.show', $creative->creator_id) }}">{{ '@'.($creative->creator->creatorProfile?->username ?? '—') }}</a> · {{ $creative->creator->email }}</dd>
                        <dt>Danh mục / Model</dt>
                        <dd>{{ $creative->category?->name ?? '—' }} · {{ $creative->model->name }} <span class="text-muted">({{ $creative->model->provider->name }})</span></dd>
                        <dt>Giá</dt>
                        <dd>@credit($creative->price_per_image) Credit / ảnh</dd>
                        <dt>Ngày đăng</dt>
                        <dd>{{ $creative->published_at?->format('d/m/Y H:i') ?? 'Chưa đăng' }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    @if ($creative->hidden_at)
                        <form method="POST" action="{{ route('admin.creatives.unhide', $creative) }}" data-confirm="Hiện lại Creative này trên Marketplace?">
                            @csrf
                            <button class="btn btn-success btn-block"><i class="fas fa-eye"></i> Hiện lại trên Marketplace</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.creatives.hide', $creative) }}" data-confirm="Ẩn Creative này khỏi Marketplace? Người dùng sẽ không xem và tạo ảnh được nữa.">
                            @csrf
                            <div class="form-group">
                                <input type="text" name="reason" class="form-control form-control-sm" maxlength="255" placeholder="Lý do ẩn (ghi vào nhật ký)">
                            </div>
                            <button class="btn btn-danger btn-block"><i class="fas fa-eye-slash"></i> Ẩn khỏi Marketplace</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Thống kê (ảnh thành công)</h3></div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between"><span>Số ảnh bán được</span><b>@credit($stats->images)</b></li>
                    <li class="list-group-item d-flex justify-content-between"><span>Doanh số</span><b>@credit($stats->gmv)</b></li>
                    <li class="list-group-item d-flex justify-content-between"><span>Phí nền tảng</span><b>@credit($stats->fee)</b></li>
                    <li class="list-group-item d-flex justify-content-between"><span>Creator nhận</span><b>@credit($stats->creator_amount)</b></li>
                </ul>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Ảnh mẫu</h3></div>
                <div class="card-body">
                    <div class="row">
                        @forelse ($creative->images as $image)
                            <div class="col-6 col-md-3 mb-3"><img src="{{ $media->url($image->path) }}" class="thumb-lg" alt=""></div>
                        @empty
                            <div class="col text-muted">Chưa có ảnh mẫu.</div>
                        @endforelse
                    </div>
                    @if ($creative->description)
                        <p class="pre-wrap mb-0">{{ $creative->description }}</p>
                    @endif
                </div>
            </div>

            <div class="card card-outline card-secondary">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-lock"></i> Cấu hình nội bộ</h3></div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt>System Prompt</dt><dd class="pre-wrap">{{ $creative->config?->system_prompt ?: '—' }}</dd>
                        <dt>Creative Prompt</dt><dd class="pre-wrap">{{ \App\Services\AI\ModelService::formatCreativePrompt($creative->config?->creative_prompt) ?: '—' }}</dd>
                        <dt>Negative Prompt</dt><dd class="pre-wrap">{{ $creative->config?->negative_prompt ?: '—' }}</dd>
                        <dt>Số ảnh tối đa / lần</dt><dd>{{ $creative->config?->max_outputs }}</dd>
                        <dt>Tỉ lệ khung hình</dt>
                        <dd>{{ $creative->config?->allowed_aspect_ratios ? implode(', ', $creative->config->allowed_aspect_ratios) : 'Tất cả tỉ lệ model hỗ trợ' }} (mặc định {{ $creative->config?->default_aspect_ratio }})</dd>
                        @if ($creative->config?->extra_params)
                            <dt>Tham số thêm</dt><dd><code>{{ json_encode($creative->config->extra_params) }}</code></dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Lượt tạo ảnh gần đây</h3></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>#</th><th>Người dùng</th><th>Loại</th><th>Ảnh</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
                        <tbody>
                        @forelse ($recentGenerations as $generation)
                            <tr>
                                <td><a href="{{ route('admin.generations.show', $generation) }}">{{ $generation->id }}</a></td>
                                <td>{{ $generation->user->email }}</td>
                                <td><x-admin.status :value="$generation->type" /></td>
                                <td>{{ $generation->success_count }}/{{ $generation->quantity }}</td>
                                <td><x-admin.status :value="$generation->status" /></td>
                                <td class="text-muted">{{ $generation->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">Chưa có lượt tạo ảnh.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
