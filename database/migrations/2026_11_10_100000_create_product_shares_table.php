<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chia sẻ sản phẩm cho tài khoản A51 khác (theo email): người được chia sẻ dùng sản phẩm để tạo ảnh / tạo lô
 * như sản phẩm của mình, nhưng không sửa, xoá, bật tắt hay chia sẻ tiếp được.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_shares');
    }
};
