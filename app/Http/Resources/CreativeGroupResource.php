<?php

namespace App\Http\Resources;

use App\Models\AiCreative;
use App\Models\CreativeGroup;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nhóm creative cho tạo lô tự động (note/auto-tao-lo.txt ở repo frontend).
 *
 * @mixin CreativeGroup
 */
class CreativeGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = app(MediaStorage::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'creatives' => $this->creatives->map(fn (AiCreative $creative) => [
                'id' => $creative->id,
                'slug' => $creative->slug,
                'title' => $creative->title,
                'cover_url' => $media->url($creative->images->first()?->thumbPathOrOriginal()),
                'price_per_image' => $creative->price_per_image,
                'status' => $creative->status,
                'is_hidden' => $creative->hidden_at !== null,
            ])->values(),
            'created_at' => $this->created_at,
        ];
    }
}
