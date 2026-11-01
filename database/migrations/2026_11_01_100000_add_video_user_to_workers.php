<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker tạo video (FlowKit auto-make-image-to-video): gắn đúng 1 tài khoản A51 (admin gắn ở trang Worker),
 * worker lấy ảnh ngẫu nhiên trong các lô ảnh của shop đó qua GET /api/v1/video-images. null = chưa gắn, không lấy được ảnh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->foreignId('video_user_id')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_user_id');
        });
    }
};
