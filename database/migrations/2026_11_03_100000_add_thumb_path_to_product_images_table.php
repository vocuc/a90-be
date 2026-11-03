<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thumbnail rộng 400px (cao theo tỷ lệ) để hiển thị ảnh sản phẩm. Ảnh cũ chưa có thumbnail thì dùng lại ảnh gốc,
 * chạy `php artisan products:make-thumbs` để tạo bù.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('thumb_path');
        });
    }
};
