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
            'cover_url' => $media->url($this->images->first()?->path),
            'images' => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $media->url($image->path),
                'field_key' => $image->field_key,
            ])->values(),
            'hashtags' => HashtagResource::collection($this->whenLoaded('hashtags')),
            // Số lần chạy tự động của người đang xem (mỗi nhân viên đếm riêng)
            'auto_batch_count' => (int) $this->auto_batch_count,
            // false: sản phẩm của shop chủ, nhân viên chỉ dùng, không sửa / xoá (note/chia-se-tai-nguyen.txt ở repo frontend)
            'is_owner' => $this->user_id === $request->user()?->id,
            'created_at' => $this->created_at,
        ];
    }
}
