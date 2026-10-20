<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('revenue:release')->hourly()->withoutOverlapping();
// Giao lại job worker giữ quá hạn, đánh dấu thất bại ảnh chờ quá lâu.
Schedule::command('generations:sweep-stuck')->everyMinute()->withoutOverlapping();
// Bài đăng kẹt ở bước tạo nội dung; job đăng bài worker giữ quá hạn; hỏi TikTok trạng thái bài gửi qua API.
Schedule::command('social:sweep')->everyMinute()->withoutOverlapping();
// Token TikTok (người dùng tự kết nối) sắp hết hạn: làm mới để không phải kết nối lại.
Schedule::command('tiktok:refresh-tokens')->dailyAt('03:30')->withoutOverlapping();
