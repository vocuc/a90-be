<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiêu đề lô (2026-11-16): lô phối đồ = hook bìa do worker gửi kèm ảnh bìa; các ảnh outfit sau chọn outfit theo tiêu đề này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
