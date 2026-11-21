<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lô phối đồ của bài "Outfit theo ngày / dịp" (outfit_by_day, người dùng chốt 2026-11-21): mỗi ảnh outfit in 1 nhãn ngày
 * ("Mùng 1: Áo dài đỏ", "Thứ 2: Đi làm") thay cho chữ "Outfit N". outfit_labels[i] = nhãn của ảnh vị trí i + 2 (ảnh 1 = bìa).
 * null = lô thường (in "Outfit N"). a90-fe note/xay-kenh-tiktok/bai-viet-mau/outfit_by_day.txt
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->json('outfit_labels')->nullable()->after('outfit_tags');
        });
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropColumn('outfit_labels');
        });
    }
};
