<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tuyến nội dung của kênh: bài viết mẫu nào dùng + tỉ lệ % (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.3).
 * Lệnh /lap-ke-hoach soạn (pending, hoặc approved nếu kênh bật tự duyệt), chủ shop duyệt / từ chối / sửa.
 * Kênh dùng bản approved mới nhất.
 */
class ChannelLineup extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'approved_at' => 'datetime'];
    }

    /** @return string[] key bài viết mẫu của tuyến */
    public function sampleKeys(): array
    {
        return array_column($this->items ?? [], 'sample_key');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
