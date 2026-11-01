<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Công tắc bật / tắt từng video template (shop tự bật tắt ở Quản lý video template, cả template đồng bộ).
 * Tắt: không random cho bài video mới, không chọn được khi sửa bài; bài đã tạo giữ nguyên. Sync không đổi công tắc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_templates', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('image_count');
        });
    }

    public function down(): void
    {
        Schema::table('video_templates', function (Blueprint $table) {
            $table->dropColumn('is_enabled');
        });
    }
};
