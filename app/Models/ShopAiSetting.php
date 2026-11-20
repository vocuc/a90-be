<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CŨ - cài đặt AI viết nội dung theo shop (trước 2026-11-20). Nay cài đặt AI nằm ở từng kênh (Channel); bảng này chỉ còn
 * để chép sang KÊNH ĐẦU TIÊN khi shop tạo kênh (ChannelService::create), sau đó xoá (a90-fe
 * note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.10). Không dùng ở chỗ nào khác.
 */
class ShopAiSetting extends Model
{
    protected $guarded = ['id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
