<?php

namespace App\Http\Resources;

use App\Models\AiCreative;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Creative trên danh sách Marketplace. KHÔNG chứa prompt hay cấu hình nội bộ.
 *
 * @mixin AiCreative
 */
class CreativeCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'price_per_image' => $this->price_per_image,
            'cover_url' => app(MediaStorage::class)->url($this->images->first()?->thumbPathOrOriginal()),
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'creator' => $this->creator->creatorProfile
                ? new CreatorSummaryResource($this->creator->creatorProfile)
                : null,
            'usage_count' => $this->usage_count,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'published_at' => $this->published_at,
        ];
    }
}
