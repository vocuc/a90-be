{{-- Font icon Material Symbols, giống font frontend dùng để hiển thị icon danh mục --}}
@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=block">
    <style>
        .category-icon { font-size: 22px; line-height: 1; vertical-align: middle; color: #004ac6; }
        .icon-preview { width: 56px; height: 56px; border-radius: 50%; background: #eaedff; display: inline-flex; align-items: center; justify-content: center; }
        .icon-preview .material-symbols-outlined { font-size: 28px; color: #004ac6; }
        .icon-pick { width: 40px; height: 40px; padding: 0; }
        .icon-pick .material-symbols-outlined { font-size: 22px; vertical-align: middle; }
    </style>
@endpush
