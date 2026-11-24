<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lô phối đồ bài "Outfit - theo sản phẩm" (one_shoe_many_looks, người dùng chốt 2026-11-24, a90-fe
 * note/xay-kenh-tiktok/bai-viet-mau/one_shoe_many_looks.txt): outfit_mode = 'product' -> worker phối đồ chọn outfit OOTD
 * cùng loại giày với sản phẩm (tags.ootd) và vẽ bằng Google Flow, giày ĐÚNG theo ảnh sản phẩm. null = lô thường (ChatGPT,
 * giày theo outfit OOTD).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->string('outfit_mode', 20)->nullable()->after('outfit_labels');
        });
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropColumn('outfit_mode');
        });
    }
};
