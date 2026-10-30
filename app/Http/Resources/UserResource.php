<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\MediaStorage;
use App\Services\SettingService;
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
            'credit_balance' => (int) ($this->creditWallet?->balance ?? 0),
            'creator_profile' => $this->creatorProfile
                ? new CreatorProfileResource($this->creatorProfile)
                : null,
            // Nhân viên của shop (note/chia-se-tai-nguyen.txt ở repo frontend): shop đang làm, logo tự tải lên để chèn vào ảnh
            'staff_of' => $this->staffMembership?->owner?->creatorProfile
                ? new CreatorSummaryResource($this->staffMembership->owner->creatorProfile)
                : null,
            'logo_url' => app(MediaStorage::class)->url($this->logo_path),
            // AI viết nội dung bài đăng (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend): tắt thì ẩn công tắc / nút viết lại
            'social_ai' => [
                'enabled' => SocialContentJobService::enabled(),
                'price' => app(SettingService::class)->int('social.ai_content_price'),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
