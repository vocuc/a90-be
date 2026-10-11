<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thumbnail nhỏ cho thẻ Marketplace. Ảnh cũ không có thumbnail thì dùng lại ảnh chính.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_creative_images', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_creative_images', function (Blueprint $table) {
            $table->dropColumn('thumb_path');
        });
    }
};
