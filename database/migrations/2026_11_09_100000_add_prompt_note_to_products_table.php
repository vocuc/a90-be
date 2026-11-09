<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Yêu cầu" của sản phẩm (note/auto-tao-lo.txt ở repo frontend): văn bản tự do, nối vào cuối prompt của mọi Creative
 * khi tạo ảnh cho sản phẩm (generations.product_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('prompt_note')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('prompt_note');
        });
    }
};
