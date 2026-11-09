<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sản phẩm cho tạo lô tự động (note/auto-tao-lo.txt ở repo frontend).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = app(MediaStorage::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            // "Yêu cầu" nối vào cuối prompt của mọi Creative khi tạo ảnh cho sản phẩm
            'prompt_note' => $this->prompt_note,
            // Tắt thì không được tạo lô tự động
            'is_active' => (bool) ($this->is_active ?? true),
            'cover_url' => $media->url($this->images->first()?->thumbPathOrOriginal()),
            'images' => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $media->url($image->path),
                'thumb_url' => $media->url($image->thumbPathOrOriginal()),
                'field_key' => $image->field_key,
            ])->values(),
            'hashtags' => HashtagResource::collection($this->whenLoaded('hashtags')),
            // Số lần chạy tự động của người đang xem (mỗi nhân viên đếm riêng)
            'auto_batch_count' => (int) $this->auto_batch_count,
            // Số ảnh đã tạo xong cho sản phẩm (generations.product_id), không tính ảnh lỗi / đang tạo / đã tắt
            'generated_image_count' => (int) $this->generated_image_count,
            'created_at' => $this->created_at,
        ];
    }
}
