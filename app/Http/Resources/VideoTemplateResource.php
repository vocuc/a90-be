<?php

namespace App\Http\Resources;

use App\Models\VideoTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VideoTemplate */
class VideoTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'video_url' => $this->video_url,
            'image_count' => $this->image_count,
            // Đồng bộ từ máy làm template: shop chỉ xem, không sửa / xoá
            'synced' => $this->isSynced(),
            'source_key' => $this->source_key,
            'created_at' => $this->created_at,
        ];
    }
}
