<?php

namespace Tests\Unit;

use App\Services\RevenueShareService;
use App\Services\SettingService;
use PHPUnit\Framework\TestCase;

class RevenueShareServiceTest extends TestCase
{
    public function test_creator_receives_configured_percent_of_price(): void
    {
        $shares = new RevenueShareService(new SettingService);

        $this->assertSame(['platform_fee_amount' => 1800, 'creator_amount' => 200], $shares->split(2000, '10.00'));
        // Phần lẻ thuộc về nền tảng.
        $this->assertSame(['platform_fee_amount' => 900, 'creator_amount' => 99], $shares->split(999, '10.00'));
        $this->assertSame(['platform_fee_amount' => 0, 'creator_amount' => 2000], $shares->split(2000, '100.00'));
        $this->assertSame(['platform_fee_amount' => 2000, 'creator_amount' => 0], $shares->split(2000, '0.00'));
    }
}
