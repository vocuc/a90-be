<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thumbnail nhỏ cho danh sách ảnh đã tạo. Ảnh cũ không có thumbnail thì dùng lại ảnh gốc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->dropColumn('thumb_path');
        });
    }
};
