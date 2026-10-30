<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI viết tiêu đề / nội dung / hashtag bằng Claude API (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend):
 * - social_posts.ai_content: người dùng bật công tắc "AI viết nội dung" khi tạo bài.
 * - social_content_jobs.charge_amount: Credit đã trừ cho lần viết này, hoàn lại khi AI lỗi.
 * - shop_hashtag: kho hashtag của shop, AI chỉ chọn hashtag trong kho này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->boolean('ai_content')->default(false)->after('source');
        });

        Schema::table('social_content_jobs', function (Blueprint $table) {
            $table->unsignedInteger('charge_amount')->default(0)->after('status');
        });

        Schema::create('shop_hashtag', function (Blueprint $table) {
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('hashtag_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['owner_id', 'hashtag_id']);
            $table->index('hashtag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_hashtag');

        Schema::table('social_content_jobs', function (Blueprint $table) {
            $table->dropColumn('charge_amount');
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('ai_content');
        });
    }
};
