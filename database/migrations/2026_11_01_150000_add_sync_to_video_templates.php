<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template đồng bộ từ máy làm template (D:\video-tmp, npm run sync-templates) vào shop worker được gắn:
 * - source_key: ID template trên máy làm template (null = shop tự tạo). Mỗi shop 1 dòng / ID.
 * - hidden_at: template đã bị xoá khỏi máy làm template -> ẩn (không random / chọn được), sync lại cùng ID thì hiện lại.
 * - video_url: template đồng bộ không có link video TikTok mẫu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_templates', function (Blueprint $table) {
            $table->string('video_url', 500)->nullable()->change();
            $table->string('source_key', 50)->nullable()->after('image_count');
            $table->timestamp('hidden_at')->nullable()->after('source_key');
            $table->unique(['user_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::table('video_templates', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'source_key']);
            $table->dropColumn(['source_key', 'hidden_at']);
        });
    }
};
