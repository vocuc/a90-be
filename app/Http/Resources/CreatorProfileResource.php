<?php

namespace App\Http\Resources;

use App\Models\CreatorProfile;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreatorProfile */
class CreatorProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = app(MediaStorage::class);

        return [
            'username' => $this->username,
            'shop_name' => $this->shop_name,
            'avatar_url' => $media->url($this->avatar_path),
            'cover_url' => $media->url($this->cover_path),
            'description' => $this->description,
            'website' => $this->website,
            'social_links' => $this->social_links ?? (object) [],
            'verified' => $this->verified,
            'followers_count' => $this->followers_count,
            'creative_count' => $this->creative_count,
            'usage_count' => $this->usage_count,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
