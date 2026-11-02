<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Người dùng chọn worker tạo ảnh khi tạo ảnh / tạo lô (note/chon-worker-tao-anh.txt ở repo frontend).
 * null: worker tạo ảnh nào được gắn cho tài khoản cũng nhận (ảnh tạo trước khi có tính năng, Creator tạo thử).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->foreignId('worker_id')->nullable()->after('insert_logo')->constrained('workers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('worker_id');
        });
    }
};
