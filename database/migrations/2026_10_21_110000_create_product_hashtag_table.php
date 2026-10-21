<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hashtag của sản phẩm (dùng chung bảng hashtags với Creative): tạo lô tự động chỉ ghép sản phẩm với nhóm có
 * creative trùng ít nhất một hashtag; sản phẩm không có hashtag thì bỏ qua.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_hashtag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hashtag_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->primary(['product_id', 'hashtag_id']);
            $table->index('hashtag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_hashtag');
    }
};
