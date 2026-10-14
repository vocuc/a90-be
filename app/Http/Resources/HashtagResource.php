<?php

namespace App\Http\Resources;

use App\Models\Hashtag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Hashtag */
class HashtagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            // Dùng để lọc: GET /creatives?hashtag={slug}
            'slug' => $this->slug,
            // Chỉ có ở API gợi ý hashtag
            'creative_count' => $this->whenHas('creatives_count'),
        ];
    }
}
