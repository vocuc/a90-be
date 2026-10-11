@extends('admin.layouts.app')
@section('title', 'Lượt tạo ảnh #'.$generation->id)
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.generations.index') }}">Lượt tạo ảnh</a></li>
    <li class="breadcrumb-item active">#{{ $generation->id }}</li>
@endsection

@inject('media', 'App\Services\MediaStorage')

@section('content')
    <div class="row">
        <div class="col-lg-4">
            <div class="card card-primary card-outline">
                <div class="card-body">
                    <p><x-admin.status :value="$generation->status" /> <x-admin.status :value="$generation->type" /></p>
                    <dl class="mb-0">
                        <dt>Người dùng</dt><dd><a href="{{ route('admin.users.show', $generation->user_id) }}">{{ $generation->user->email }}</a></dd>
                        <dt>Creative</dt><dd><a href="{{ route('admin.creatives.show', $generation->creative_id) }}">{{ $generation->creative->title }}</a></dd>
                        <dt>Creator</dt><dd>{{ '@'.($generation->creator->creatorProfile?->username ?? '—') }}</dd>
                        <dt>Model</dt><dd>{{ $generation->model->name }} <code>{{ $generation->model->code }}</code></dd>
                        <dt>Số lượng / tỉ lệ</dt><dd>{{ $generation->quantity }} ảnh · {{ $generation->aspect_ratio }}</dd>
                        <dt>Giá / ảnh (snapshot)</dt><dd>@credit($generation->price_per_image) Credit</dd>
                        @if ($generation->parent_generation_id)
                            <dt>Retry của</dt><dd><a href="{{ route('admin.generations.show', $generation->parent_generation_id) }}">#{{ $generation->parent_generation_id }}</a></dd>
                        @endif
                        <dt>Idempotency key</dt><dd><code class="small">{{ $generation->idempotency_key }}</code></dd>
                        <dt>Tạo lúc / xong lúc</dt><dd>{{ $generation->created_at->format('d/m/Y H:i:s') }} → {{ $generation->completed_at?->format('H:i:s') ?? '…' }}</dd>
                    </dl>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Ảnh đầu vào</h3></div>
                <div class="card-body">
                    @forelse($generation->input_paths ?? [] as $key => $path)
                        <figure class="d-inline-block mr-2">
                            <img src="{{ $media->url($path) }}" class="thumb-lg" alt="">
                            <figcaption class="small text-muted"><code>{{ $key }}</code></figcaption>
                        </figure>
                    @empty
                        <span class="text-muted">Không có ảnh.</span>
                    @endforelse
                    @if($generation->input_values)
                        <dl class="mt-2 mb-0 small">
                            @foreach($generation->input_values as $key => $value)
                                <dt><code>{{ $key }}</code></dt><dd>{{ is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'Có' : 'Không') : $value) }}</dd>
                            @endforeach
                        </dl>
                    @endif
                </div>
            </div>
            <div class="card card-outline card-secondary collapsed-card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-lock"></i> Prompt đã gửi</h3>
                    <div class="card-tools"><button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div>
                </div>
                <div class="card-body"><div class="pre-wrap small">{{ $generation->prompt_snapshot }}</div></div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Kết quả từng ảnh</h3></div>
                <div class="card-body">
                    <div class="row">
                        @foreach ($generation->outputs as $output)
                            <div class="col-md-6 col-xl-4 mb-3">
                                <div class="card h-100 mb-0">
                                    @if ($output->image_path)
                                        <img src="{{ $media->url($output->image_path) }}" class="card-img-top" style="aspect-ratio:1;object-fit:cover" alt="">
                                    @else
                                        <div class="d-flex align-items-center justify-content-center bg-light text-muted" style="aspect-ratio:1">
                                            <i class="fas {{ $output->status->value === 'failed' ? 'fa-times-circle fa-2x text-danger' : 'fa-hourglass-half fa-2x' }}"></i>
                                        </div>
                                    @endif
                                    <div class="card-body p-2 small">
                                        <div class="d-flex justify-content-between mb-1">
                                            <b>Ảnh {{ $output->position }}</b> <x-admin.status :value="$output->status" />
                                        </div>
                                        @if ($output->error_code)
                                            <div class="text-danger"><code>{{ $output->error_code }}</code> {{ $output->error_message }}</div>
                                        @endif
                                        @if ($output->transaction)
                                            <div>TX <code>{{ $output->transaction->code }}</code> <x-admin.status :value="$output->transaction->status" /></div>
                                            <div class="text-muted">@credit($output->transaction->amount) · phí @credit($output->transaction->platform_fee_amount) · Creator @credit($output->transaction->creator_amount)</div>
                                        @endif
                                        @if ($output->worker)
                                            <div class="text-muted">Worker: {{ $output->worker->name }} · lần nhận {{ $output->attempts }}</div>
                                        @endif
                                        @if ($output->retry_of_output_id)
                                            <div class="text-muted">Retry của ảnh #{{ $output->retry_of_output_id }}</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
