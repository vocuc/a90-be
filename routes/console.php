<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('revenue:release')->hourly()->withoutOverlapping();
// Giao lại job worker giữ quá hạn, đánh dấu thất bại ảnh chờ quá lâu.
Schedule::command('generations:sweep-stuck')->everyMinute()->withoutOverlapping();
// Bài TikTok kẹt ở bước tạo nội dung; job đăng bài worker giữ quá hạn.
Schedule::command('tiktok:sweep')->everyMinute()->withoutOverlapping();
