@php
    $menu = [
        ['header' => 'TỔNG QUAN'],
        ['route' => 'admin.dashboard', 'pattern' => 'admin.dashboard', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
        ['header' => 'NGƯỜI DÙNG'],
        ['route' => 'admin.users.index', 'pattern' => 'admin.users.*', 'icon' => 'fas fa-users', 'label' => 'Người dùng'],
        ['route' => 'admin.creators.index', 'pattern' => 'admin.creators.*', 'icon' => 'fas fa-store', 'label' => 'Creator'],
        ['header' => 'NỘI DUNG'],
        ['route' => 'admin.creatives.index', 'pattern' => 'admin.creatives.*', 'icon' => 'fas fa-magic', 'label' => 'AI Creative'],
        ['route' => 'admin.categories.index', 'pattern' => 'admin.categories.*', 'icon' => 'fas fa-tags', 'label' => 'Danh mục'],
        ['route' => 'admin.generations.index', 'pattern' => 'admin.generations.*', 'icon' => 'fas fa-images', 'label' => 'Lượt tạo ảnh'],
        ['header' => 'TÀI CHÍNH'],
        ['route' => 'admin.transactions.index', 'pattern' => 'admin.transactions.*', 'icon' => 'fas fa-exchange-alt', 'label' => 'Transaction'],
        ['route' => 'admin.ledger.index', 'pattern' => 'admin.ledger.*', 'icon' => 'fas fa-book', 'label' => 'Sổ cái Credit'],
        ['route' => 'admin.revenues.index', 'pattern' => 'admin.revenues.*', 'icon' => 'fas fa-hand-holding-usd', 'label' => 'Doanh thu Creator'],
        ['header' => 'HỆ THỐNG'],
        ['route' => 'admin.settings.edit', 'pattern' => 'admin.settings.*', 'icon' => 'fas fa-cogs', 'label' => 'Cấu hình'],
        ['route' => 'admin.audit-logs.index', 'pattern' => 'admin.audit-logs.*', 'icon' => 'fas fa-history', 'label' => 'Nhật ký hệ thống'],
    ];
@endphp
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <a href="{{ route('admin.dashboard') }}" class="brand-link">
        <span class="brand-image img-circle elevation-3 bg-primary d-inline-flex align-items-center justify-content-center"
              style="width:33px;height:33px;opacity:.9"><i class="fas fa-bolt text-white"></i></span>
        <span class="brand-text font-weight-bold">AI90 Admin</span>
    </a>
    <div class="sidebar">
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                @foreach ($menu as $item)
                    @if (isset($item['header']))
                        <li class="nav-header">{{ $item['header'] }}</li>
                    @else
                        <li class="nav-item">
                            <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['pattern']) ? 'active' : '' }}">
                                <i class="nav-icon {{ $item['icon'] }}"></i>
                                <p>{{ $item['label'] }}</p>
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
        </nav>
    </div>
</aside>
