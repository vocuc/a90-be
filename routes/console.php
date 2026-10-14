<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('revenue:release')->hourly()->withoutOverlapping();
// Giao lại job worker giữ quá hạn, đánh dấu thất bại ảnh chờ quá lâu.
Schedule::command('generations:sweep-stuck')->everyMinute()->withoutOverlapping();
// Bài TikTok kẹt ở bước tạo nội dung; làm mới token TikTok sắp hết hạn.
Schedule::command('tiktok:sweep')->everyMinute()->withoutOverlapping();
Schedule::command('tiktok:refresh-tokens')->daily()->withoutOverlapping();
