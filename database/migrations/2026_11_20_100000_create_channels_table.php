<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kênh (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.1): 1 tài khoản TikTok của shop, đăng qua 1 worker
 * publish riêng (worker_id unique). Mỗi kênh có chân dung khách hàng, cấu hình kế hoạch và cài đặt AI viết nội dung
 * riêng (chuyển từ shop_ai_settings / shop_hashtag sang). Mọi bài đăng thuộc 1 kênh (social_posts.channel_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // 1 worker chỉ thuộc 1 kênh; worker bị xoá / gỡ khỏi shop -> null, kênh chờ chọn worker khác
            $table->foreignId('worker_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('platform', 20)->default('tiktok');
            // Chân dung khách hàng: { age_range, gender, occupation, price_range, style, pain_points, occasions, tone,
            // active_hours, note }
            $table->json('persona')->nullable();
            // Cấu hình kế hoạch
            $table->unsignedTinyInteger('plan_days')->default(7);
            $table->unsignedTinyInteger('posts_per_day')->default(3);
            // [{ start: "11:00", end: "13:00" }]
            $table->json('time_slots')->nullable();
            $table->unsignedTinyInteger('video_percent')->default(0);
            $table->boolean('auto_approve_lineup')->default(false);
            $table->boolean('auto_approve_templates')->default(false);
            $table->boolean('auto_approve_plan')->default(false);
            // Cài đặt AI viết nội dung (trước ở shop_ai_settings)
            $table->unsignedSmallInteger('title_max');
            $table->unsignedSmallInteger('content_max');
            $table->text('requirements')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('user_id');
        });

        // Kho hashtag của kênh: AI chỉ chọn hashtag trong kho này
        Schema::create('channel_hashtag', function (Blueprint $table) {
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hashtag_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['channel_id', 'hashtag_id']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        // Bài viết mẫu cũ (học giọng văn) bỏ hẳn: giọng văn viết vào ô Yêu cầu của kênh
        if (Schema::hasColumn('shop_ai_settings', 'samples')) {
            Schema::table('shop_ai_settings', function (Blueprint $table) {
                $table->dropColumn('samples');
            });
        }
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
        });
        Schema::dropIfExists('channel_hashtag');
        Schema::dropIfExists('channels');
        Schema::table('shop_ai_settings', function (Blueprint $table) {
            $table->json('samples')->nullable()->after('content_max');
        });
    }
};
