<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kế hoạch nội dung của kênh (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.3 - 4.9). Lệnh /lap-ke-hoach
 * (skill D:\FlowKit\.claude\skills\lap-ke-hoach) soạn tuyến nội dung, template chữ, kỳ kế hoạch rồi gửi lên qua API
 * /api/v1/channel-plan; chủ shop duyệt trên A51; duyệt kỳ -> sinh bài đăng; bài ảnh có chữ -> worker đăng bài của kênh
 * chèn chữ (text_overlay_jobs) trước khi tới giờ đăng.
 * Tên bảng / cột KHÁC bản "kế hoạch nội dung" cũ đã gỡ (plan_contexts, social_posts.script) để không lẫn.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tuyến nội dung: bài viết mẫu nào dùng + tỉ lệ %. Kênh dùng bản approved mới nhất.
        Schema::create('channel_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            // [{ sample_key, percent, reason }]
            $table->json('items');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'status']);
        });

        // Template chữ chèn lên ảnh
        Schema::create('channel_text_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('status', 20);
            // { position, font, weight, size, color, stroke, background, box_color, box_radius, align, max_lines }
            $table->json('style');
            $table->string('sample_text', 150)->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'status']);
        });

        // Kỳ kế hoạch
        Schema::create('content_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->unsignedTinyInteger('days');
            $table->string('status', 20);
            // Slot giờ backend cấp lúc lấy context: [{ index, at, format }]
            $table->json('slots');
            $table->json('warnings')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'status']);
        });

        Schema::create('content_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('content_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot_index');
            $table->timestamp('scheduled_at');
            $table->string('sample_key', 50);
            $table->string('hook', 200);
            $table->string('format', 10);
            $table->foreignId('video_template_id')->nullable()->constrained()->nullOnDelete();
            // [{ generation_id, output_id, text }] đúng thứ tự slide
            $table->json('images');
            $table->foreignId('text_template_id')->nullable()->constrained('channel_text_templates')->nullOnDelete();
            $table->string('title', 150)->default('');
            $table->text('content')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('status', 20);
            $table->foreignId('social_post_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['plan_id', 'slot_index']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('plan_item_id')->nullable()->after('channel_id')->constrained('content_plan_items')->nullOnDelete();
            $table->string('sample_key', 50)->nullable()->after('plan_item_id');
            $table->string('hook', 200)->nullable()->after('sample_key');
            // Chủ shop bấm "Làm lại": lần chạy /lap-ke-hoach kế tiếp làm lại bài này
            $table->timestamp('redo_requested_at')->nullable()->after('hook');
        });

        Schema::table('social_post_images', function (Blueprint $table) {
            $table->string('overlay_text', 150)->nullable();
            $table->foreignId('text_template_id')->nullable()->constrained('channel_text_templates')->nullOnDelete();
            // Ảnh đã chèn chữ (worker gửi về); ảnh gốc giữ để chèn lại khi sửa chữ
            $table->string('overlay_path', 500)->nullable();
            $table->string('overlay_status', 20)->nullable();
        });

        // Việc chèn chữ: 1 bài = 1 job, worker đăng bài của kênh làm (nhận qua /api/v1/post-jobs, type overlay)
        Schema::create('text_overlay_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->index(['worker_id', 'status']);
            $table->index(['social_post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_overlay_jobs');
        Schema::table('social_post_images', function (Blueprint $table) {
            $table->dropConstrainedForeignId('text_template_id');
            $table->dropColumn(['overlay_text', 'overlay_path', 'overlay_status']);
        });
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_item_id');
            $table->dropColumn(['sample_key', 'hook', 'redo_requested_at']);
        });
        Schema::dropIfExists('content_plan_items');
        Schema::dropIfExists('content_plans');
        Schema::dropIfExists('channel_text_templates');
        Schema::dropIfExists('channel_lineups');
    }
};
