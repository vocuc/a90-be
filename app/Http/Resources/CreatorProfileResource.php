<?php

namespace App\Http\Resources;

use App\Models\CreatorProfile;
use App\Services\MediaStorage;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreatorProfile */
class CreatorProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = app(MediaStorage::class);
        $isOwner = $request->user()?->id === $this->user_id;

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
            $this->mergeWhen($isOwner, fn () => [
                'platform_fee_percent' => (float) ($this->platform_fee_percent
                    ?? app(SettingService::class)->get('platform_fee_percent')),
            ]),
        ];
    }
}
