<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chèn logo shop (ảnh đại diện shop) vào ảnh lúc tạo ảnh: bật / tắt theo lô, lưu vào từng generation của lô.
 * Worker tạo ảnh nhận link logo kèm job (note/logo-tao-anh.txt ở repo frontend).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->boolean('insert_logo')->default(false)->after('input_values');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('insert_logo');
        });
    }
};
