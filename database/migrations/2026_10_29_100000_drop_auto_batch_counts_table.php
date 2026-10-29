<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo lô tự động chọn ngẫu nhiên sản phẩm / nhóm, không còn xoay vòng theo lượt sản phẩm x nhóm.
 * Giữ auto_batch_product_counts (số lô đã tạo của từng sản phẩm).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('auto_batch_counts');
    }

    public function down(): void
    {
        Schema::create('auto_batch_counts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('creative_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count')->default(0);
            $table->primary(['user_id', 'product_id', 'creative_group_id']);
        });
    }
};
