<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chủ đề kênh chỉ còn tên + ghi chú (người dùng chốt 2026-11-22): bỏ bộ lọc tag sản phẩm của chủ đề (xoá luôn, chủ shop tự
 * viết ghi chú). Lệnh lập kế hoạch đọc ghi chú rồi tự chọn tag lọc sản phẩm + viết hook; không có ghi chú thì sáng tạo tự do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->text('note')->nullable()->after('name');
        });
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }

    public function down(): void
    {
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('name');
        });
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
