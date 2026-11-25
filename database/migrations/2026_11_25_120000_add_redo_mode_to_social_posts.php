<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểu làm lại bài kênh (2026-11-25): full = "Làm lại bài này" (lệnh lập kế hoạch làm lại cả bài, có thể tạo lô ảnh mới);
 * content = "Viết lại bằng AI" (lệnh chỉ viết lại hook / tiêu đề / caption / hashtag, giữ ảnh). null khi không chờ làm lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('redo_mode', 20)->nullable()->after('redo_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('redo_mode');
        });
    }
};
