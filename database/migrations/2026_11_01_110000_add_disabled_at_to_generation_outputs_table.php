<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Người dùng tự tắt ảnh đã tạo (không thích, ảnh lỗi...): ảnh không còn trả về ở lịch sử, không tải về, không chọn cho
 * bài đăng và không gửi cho worker. Không hoàn tiền. Tách khỏi status vì status là vòng đời tạo ảnh (worker, hoàn tiền).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
