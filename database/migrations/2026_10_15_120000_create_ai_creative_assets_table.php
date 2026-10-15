<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nguyên liệu ẩn: ảnh Creator tải lên cho trường hidden_image của template.
 * Người mua không thấy; ảnh luôn được gửi kèm tới model khi tạo ảnh.
 * Tải lên trước khi lưu Creative (creative_id null), lưu Creative thì gắn vào.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_creative_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('creative_id')->nullable()->constrained('ai_creatives')->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });

        // Ảnh nguyên liệu ẩn tại thời điểm tạo ảnh: { "logo": "creatives/assets/..." }. Không trả cho người mua.
        Schema::table('generations', function (Blueprint $table) {
            $table->json('asset_paths')->nullable()->after('input_paths');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('asset_paths');
        });
        Schema::dropIfExists('ai_creative_assets');
    }
};
