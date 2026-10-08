<?php

namespace App\Http\Resources;

use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Thông tin Creator rút gọn hiển thị kèm Creative. @mixin \App\Models\CreatorProfile */
class CreatorSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->username,
            'shop_name' => $this->shop_name,
            'avatar_url' => app(MediaStorage::class)->url($this->avatar_path),
            'verified' => $this->verified,
        ];
    }
}
