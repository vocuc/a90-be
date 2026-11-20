<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lô phối đồ tạo từ bài kế hoạch lookbook (a90-fe note/xay-kenh-tiktok/bai-viet-mau/lookbook.txt, người dùng chốt
 * 2026-11-21): lô có sẵn hook (title) -> hook_locked = true, worker phối đồ KHÔNG tạo hook mới / không ghi đè tiêu đề;
 * outfit_tags = hashtag chủ đề của bài, worker dùng để tìm outfit trên OOTD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->boolean('hook_locked')->default(false)->after('title');
            $table->json('outfit_tags')->nullable()->after('hook_locked');
        });
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropColumn(['hook_locked', 'outfit_tags']);
        });
    }
};
