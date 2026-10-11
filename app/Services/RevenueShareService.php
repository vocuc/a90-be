<?php

namespace App\Services;

use App\Models\CreatorProfile;

class RevenueShareService
{
    public function __construct(private SettingService $settings) {}

    /** % Creator nhận trên mỗi ảnh, null thì dùng mức mặc định của nền tảng. */
    public function percentFor(int $creatorId): string
    {
        $custom = CreatorProfile::query()->where('user_id', $creatorId)->value('creator_share_percent');

        return number_format((float) ($custom ?? $this->settings->get('creator_share_percent')), 2, '.', '');
    }

    /**
     * Chia tiền một Transaction: Creator nhận percent% amount. Phần lẻ thuộc về nền tảng, tổng luôn bằng amount.
     *
     * @return array{platform_fee_amount: int, creator_amount: int}
     */
    public function split(int $amount, string $percent): array
    {
        $basisPoints = (int) round(((float) $percent) * 100);
        $creatorAmount = intdiv($amount * $basisPoints, 10000);

        return [
            'platform_fee_amount' => $amount - $creatorAmount,
            'creator_amount' => $creatorAmount,
        ];
    }
}
