<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template chữ gắn với 1 hoặc nhiều loại bài viết mẫu (key trong PostSamples, vd lookbook, this_or_that). Người dùng chốt
 * 2026-11-21: template KHÔNG gắn loại bài nào thì không được dùng (lập kỳ, sửa chữ, thay template đều bỏ qua).
 * Template cũ để trống -> chủ shop gắn lại ở tab Template chữ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_text_templates', function (Blueprint $table) {
            $table->json('sample_keys')->nullable()->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('channel_text_templates', function (Blueprint $table) {
            $table->dropColumn('sample_keys');
        });
    }
};
