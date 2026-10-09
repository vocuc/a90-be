<?php

namespace App\Http\Resources;

use App\Models\Banner;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Banner */
class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'image_url' => app(MediaStorage::class)->url($this->image_path),
            'link_url' => $this->link_url,
        ];
    }
}
