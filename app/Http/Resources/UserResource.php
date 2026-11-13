<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Social\SocialContentJobService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'creator_profile' => $this->creatorProfile
                ? new CreatorProfileResource($this->creatorProfile)
                : null,
            // AI viết nội dung bài đăng (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend): tắt thì ẩn công tắc / nút viết lại
            'social_ai' => [
                'enabled' => SocialContentJobService::enabled(),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
