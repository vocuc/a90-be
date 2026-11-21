<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag sản phẩm do AI gắn + chủ đề kênh (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt, chốt 2026-11-21).
 * - products: ai_label + tags (enum App\Support\ProductTags), tag_sources = nguồn từng trường (ai | owner): AI chỉ ghi
 *   trường do AI đặt; tagged_image_id = ảnh bìa AI đã xem (đổi ảnh bìa -> cần gắn lại).
 * - channel_topics: chủ đề CỤ THỂ của kênh, dùng chung mọi loại bài; bài đăng / bài kế hoạch ghi topic_id để sau thống kê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('ai_label', 60)->nullable()->after('prompt_note');
            // { kieu, got, mau: [], phong_cach: [], dip: [] }
            $table->json('tags')->nullable()->after('ai_label');
            // { ai_label: ai|owner, kieu: ai|owner, ... }
            $table->json('tag_sources')->nullable()->after('tags');
            $table->timestamp('tagged_at')->nullable()->after('tag_sources');
            $table->unsignedBigInteger('tagged_image_id')->nullable()->after('tagged_at');
        });

        Schema::create('channel_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Bộ lọc tag sản phẩm của chủ đề (cùng enum với products.tags)
            $table->json('tags')->nullable();
            $table->string('source', 10);
            $table->string('status', 10)->default('active');
            $table->timestamps();
            $table->unique(['channel_id', 'name']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('topic_id')->nullable()->after('hook')->constrained('channel_topics')->nullOnDelete();
        });
        Schema::table('content_plan_items', function (Blueprint $table) {
            $table->foreignId('topic_id')->nullable()->after('hook')->constrained('channel_topics')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_plan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('topic_id');
        });
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('topic_id');
        });
        Schema::dropIfExists('channel_topics');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['ai_label', 'tags', 'tag_sources', 'tagged_at', 'tagged_image_id']);
        });
    }
};
