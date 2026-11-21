<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài viết mẫu lấy ảnh bằng LÔ TỰ ĐỘNG mới (image_source product_batch, hiện: unboxing - a90-fe
 * note/xay-kenh-tiktok/bai-viet-mau/unboxing.txt): bài kế hoạch chỉ ghi sản phẩm { product_id }, duyệt kỳ -> A51 tạo lô tự
 * động (sản phẩm x 1 nhóm Creative, mỗi Creative 1 ảnh) làm ảnh của bài.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plan_items', function (Blueprint $table) {
            $table->json('product_batch')->nullable()->after('outfit');
        });
    }

    public function down(): void
    {
        Schema::table('content_plan_items', function (Blueprint $table) {
            $table->dropColumn('product_batch');
        });
    }
};
