<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ chức năng nhân viên của shop: xoá bảng shop_staff, logo tự tải của nhân viên (users.logo_path) và
 * image_batches.shop_owner_id. Giữ nguyên bảng đếm lô tự động theo từng người (auto_batch_product_counts, auto_batch_counts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_owner_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
        Schema::dropIfExists('shop_staff');
    }

    public function down(): void
    {
        Schema::create('shop_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('password');
        });
        Schema::table('image_batches', function (Blueprint $table) {
            $table->foreignId('shop_owner_id')->nullable()->after('creative_group_id')->constrained('users')->nullOnDelete();
        });
    }
};
