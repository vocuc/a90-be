<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lô phối đồ tạo từ bài kế hoạch lookbook (người dùng chốt 2026-11-21): ảnh bìa in hook theo TEMPLATE CHỮ của kênh trên
 * A51 (không dùng mẫu bìa OOTD). Worker phối đồ v2 đọc style qua job (batch.text_template).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->foreignId('text_template_id')->nullable()->after('outfit_tags')
                ->constrained('channel_text_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('text_template_id');
        });
    }
};
