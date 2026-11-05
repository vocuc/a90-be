<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sản phẩm mà generation tạo ảnh cho (lô tự động lấy từ image_batches.product_id).
 * 0: không gắn sản phẩm (tạo lẻ, lô thủ công, Creator tạo thử). Không đặt khoá ngoại vì giá trị mặc định là 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->default(0)->after('worker_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
            $table->dropColumn('product_id');
        });
    }
};
