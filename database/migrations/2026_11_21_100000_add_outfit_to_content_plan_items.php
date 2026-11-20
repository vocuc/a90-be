<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài viết mẫu lấy ảnh bằng LÔ PHỐI ĐỒ (vd Lookbook N outfit, người dùng chốt 2026-11-21): bài kế hoạch không chọn ảnh có
 * sẵn mà ghi số ảnh (+ sản phẩm nếu có); duyệt kỳ -> tạo 1 lô phối đồ, worker phối đồ chạy như luồng hiện tại, ảnh xong
 * thì bài vào hàng chờ đăng. outfit: { count, product_id|null }.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plan_items', function (Blueprint $table) {
            $table->json('outfit')->nullable()->after('images');
        });
    }

    public function down(): void
    {
        Schema::table('content_plan_items', function (Blueprint $table) {
            $table->dropColumn('outfit');
        });
    }
};
