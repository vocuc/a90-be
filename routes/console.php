<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('revenue:release')->hourly()->withoutOverlapping();
// Giao lại job worker giữ quá hạn, đánh dấu thất bại ảnh chờ quá lâu.
Schedule::command('generations:sweep-stuck')->everyMinute()->withoutOverlapping();
// Bài đăng kẹt ở bước tạo nội dung (AI viết quá lâu).
Schedule::command('social:sweep')->everyMinute()->withoutOverlapping();
