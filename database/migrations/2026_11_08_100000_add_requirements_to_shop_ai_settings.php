<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Yêu cầu bắt buộc" của shop ở trang "AI viết nội dung" (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend):
 * văn bản tự do, AI luôn làm theo trong mọi bài (sau quy tắc chung của hệ thống).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ai_settings', function (Blueprint $table) {
            $table->text('requirements')->nullable()->after('samples');
        });
    }

    public function down(): void
    {
        Schema::table('shop_ai_settings', function (Blueprint $table) {
            $table->dropColumn('requirements');
        });
    }
};
