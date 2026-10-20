@php
    use App\Http\Controllers\Admin\TikTokStatusController;
    $status = $result['status'] ?? null;
    $statusClass = match ($status) {
        'PUBLISH_COMPLETE' => 'badge-success',
        'SEND_TO_USER_INBOX' => 'badge-info',
        'FAILED' => 'badge-danger',
        default => 'badge-secondary',
    };
@endphp
@extends('admin.layouts.app')
@section('title', 'Tra trạng thái TikTok')
@section('breadcrumb')
    <li class="breadcrumb-item active">Tra trạng thái TikTok</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form method="GET" action="{{ route('admin.tiktok-status') }}" class="card">
                <div class="card-body">
                    <label for="publish_id">publish_id</label>
                    <div class="input-group">
                        <input type="text" id="publish_id" name="publish_id" required autofocus
                               value="{{ $publishId }}" placeholder="vd: p_inbox_url~v2.7698738482990139398"
                               class="form-control text-monospace">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fas fa-search"></i> Tra</button>
                        </div>
                    </div>
                    <small class="form-text text-muted">
                        Cột <code>external_id</code> của <code>social_publish_jobs</code> (bài gửi TikTok qua API).
                        Trang chỉ hỏi TikTok, không đổi trạng thái bài.
                    </small>
                </div>
            </form>

            @if ($error)
                <div class="alert alert-danger">{{ $error }}</div>
            @endif

            @if ($result !== null)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">TikTok trả về</h3></div>
                    <div class="card-body">
                        <p>
                            <span class="badge {{ $statusClass }}">{{ $status ?? 'Không có status' }}</span>
                            {{ TikTokStatusController::STATUS_LABELS[$status] ?? '' }}
                        </p>
                        @if (! empty($result['fail_reason']))
                            <p class="text-danger">Lý do lỗi: <code>{{ $result['fail_reason'] }}</code></p>
                        @endif
                        <pre class="bg-light p-2 mb-0">{{ json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            @endif
        </div>

        @if ($job)
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Job trên A51 #{{ $job->id }}</h3></div>
                    <div class="card-body">
                        <dl class="mb-0">
                            <dt>Bài</dt>
                            <dd>#{{ $job->social_post_id }} · {{ $job->post?->title ?: '(không tiêu đề)' }}</dd>
                            <dt>Người dùng A51</dt>
                            <dd>{{ $job->post?->user?->email ?? '—' }}</dd>
                            <dt>Tài khoản TikTok</dt>
                            <dd>
                                @if ($account = $job->publishAccount)
                                    {{ $account->name }}{{ $account->username ? ' (@'.$account->username.')' : '' }}
                                @else
                                    <span class="text-muted">Đã huỷ kết nối</span>
                                @endif
                            </dd>
                            <dt>Trạng thái job</dt>
                            <dd>
                                {{ $job->status->value }}
                                @if ($job->error_code)
                                    · <code>{{ $job->error_code }}</code>
                                @endif
                            </dd>
                            <dt>Tạo lúc</dt>
                            <dd>{{ $job->created_at?->format('d/m/Y H:i') }}</dd>
                            <dt>Hỏi TikTok gần nhất (sweep)</dt>
                            <dd class="mb-0">{{ $job->checked_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
