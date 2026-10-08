@props(['value'])
@php
    $key = $value instanceof \BackedEnum ? $value->value : (string) $value;
    $map = [
        // Creative
        'draft' => ['secondary', 'Nháp'],
        'pending_review' => ['info', 'Chờ duyệt'],
        'published' => ['success', 'Đã xuất bản'],
        'rejected' => ['danger', 'Từ chối'],
        'paused' => ['warning', 'Tạm dừng'],
        'archived' => ['dark', 'Lưu trữ'],
        // Generation / Output / Transaction
        'queued' => ['secondary', 'Chờ xử lý'],
        'processing' => ['info', 'Đang xử lý'],
        'completed' => ['success', 'Hoàn tất'],
        'failed' => ['danger', 'Thất bại'],
        'cancelled' => ['dark', 'Đã hủy'],
        'pending' => ['warning', 'Chờ'],
        'refunded' => ['primary', 'Đã hoàn'],
        // Revenue
        'available' => ['success', 'Khả dụng'],
        'withdrawn' => ['info', 'Đã rút'],
        'reversed' => ['danger', 'Thu hồi'],
        // User / API key
        'active' => ['success', 'Hoạt động'],
        'banned' => ['danger', 'Bị khóa'],
        'invalid' => ['danger', 'Key sai'],
        'quota_exceeded' => ['warning', 'Hết quota'],
        'disabled' => ['secondary', 'Tắt'],
        // Generation type
        'paid' => ['primary', 'Trả phí'],
        'owner' => ['info', 'Creator tự dùng'],
        'test' => ['secondary', 'Tạo thử'],
    ];
    [$color, $label] = $map[$key] ?? ['light', $key];
@endphp
<span {{ $attributes->merge(['class' => "badge badge-{$color}"]) }}>{{ $label }}</span>
