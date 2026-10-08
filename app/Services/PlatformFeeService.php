<?php

namespace App\Services;

use App\Models\CreatorProfile;

class PlatformFeeService
{
    public function __construct(private SettingService $settings) {}

    /** Mức phí của Creator, null thì dùng mức mặc định của nền tảng. */
    public function percentFor(int $creatorId): string
    {
        $custom = CreatorProfile::query()->where('user_id', $creatorId)->value('platform_fee_percent');

        return number_format((float) ($custom ?? $this->settings->get('platform_fee_percent')), 2, '.', '');
    }

    /**
     * Chia tiền một Transaction. Phần lẻ thuộc về nền tảng, tổng luôn bằng amount.
     *
     * @return array{platform_fee_amount: int, creator_amount: int}
     */
    public function split(int $amount, string $percent): array
    {
        $basisPoints = (int) round(((float) $percent) * 100);
        $creatorAmount = intdiv($amount * (10000 - $basisPoints), 10000);

        return [
            'platform_fee_amount' => $amount - $creatorAmount,
            'creator_amount' => $creatorAmount,
        ];
    }
}
