<?php

namespace App\Http\Resources;

use App\Models\AiCreative;
use App\Services\GenerationService;
use App\Services\MediaStorage;
use Illuminate\Http\Request;

/**
 * Chi tiết Creative cho người mua: thêm ảnh mẫu và các tùy chọn tạo ảnh. KHÔNG chứa prompt.
 *
 * @mixin AiCreative
 */
class CreativeDetailResource extends CreativeCardResource
{
    public function toArray(Request $request): array
    {
        $media = app(MediaStorage::class);

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'images' => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $media->url($image->path),
            ])->values(),
            'options' => [
                'max_outputs' => $this->config?->max_outputs,
                'aspect_ratios' => GenerationService::allowedAspectRatios($this->resource),
                'default_aspect_ratio' => $this->config?->default_aspect_ratio,
            ],
        ];
    }
}
