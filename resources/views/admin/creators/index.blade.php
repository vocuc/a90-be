@extends('admin.layouts.app')
@section('title', 'Creator')
@section('breadcrumb')<li class="breadcrumb-item active">Creator</li>@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <form class="form-inline filter-bar" method="GET">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm mr-2 mb-1" placeholder="Username, tên shop, email">
                <select name="verified" class="custom-select custom-select-sm mr-2 mb-1">
                    <option value="">Tất cả</option>
                    <option value="1" @selected(($filters['verified'] ?? '') === '1')>Đã xác minh</option>
                    <option value="0" @selected(($filters['verified'] ?? '') === '0')>Chưa xác minh</option>
                </select>
                <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> Lọc</button>
            </form>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                <tr>
                    <th>Creator</th><th>Email</th><th class="text-center">Creative</th><th class="text-right">Lượt dùng</th>
                    <th style="width:200px">Xác minh</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($creators as $creator)
                    <tr>
                        <td>
                            <b>{{ $creator->shop_name }}</b>
                            @if ($creator->verified)<i class="fas fa-check-circle text-primary" title="Đã xác minh"></i>@endif
                            <div class="text-muted small">{{ '@'.$creator->username }}</div>
                        </td>
                        <td><a href="{{ route('admin.users.show', $creator->user_id) }}">{{ $creator->user->email }}</a></td>
                        <td class="text-center"><a href="{{ route('admin.creatives.index', ['q' => $creator->username]) }}">{{ $creator->creative_count }}</a></td>
                        <td class="text-right">@number($creator->usage_count)</td>
                        <td>
                            <form method="POST" action="{{ route('admin.creators.update', $creator) }}" class="form-inline">
                                @csrf @method('PUT')
                                <input type="hidden" name="verified" value="0">
                                <div class="custom-control custom-switch mr-2">
                                    <input type="checkbox" class="custom-control-input" id="verified-{{ $creator->id }}" name="verified" value="1" @checked($creator->verified)>
                                    <label class="custom-control-label" for="verified-{{ $creator->id }}">Verified</label>
                                </div>
                                <button class="btn btn-sm btn-outline-primary">Lưu</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">Chưa có Creator.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">{{ $creators->links() }}</div>
    </div>
@endsection
