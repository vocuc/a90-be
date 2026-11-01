<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài đăng video (note/lap-lich-worker.txt ở repo frontend): worker dựng video từ template + ảnh của bài rồi tự đăng,
 * chỉ nhận bài khi đã tới giờ đăng (scheduled_at).
 * - video_templates.image_count: số ảnh template cần (null với template tạo trước, chưa dùng được cho bài video).
 * - social_posts: type photo | video; với bài video: worker được chọn, template (giữ bản chụp tên / link / số ảnh lúc
 *   chọn), sản phẩm lấy ảnh, giờ đăng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_templates', function (Blueprint $table) {
            $table->unsignedSmallInteger('image_count')->nullable()->after('video_url');
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('type', 10)->default('photo')->after('user_id');
            $table->foreignId('worker_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->foreignId('video_template_id')->nullable()->after('worker_id')->constrained()->nullOnDelete();
            $table->string('video_template_name', 150)->nullable()->after('video_template_id');
            $table->string('video_template_url', 500)->nullable()->after('video_template_name');
            $table->unsignedSmallInteger('video_image_count')->nullable()->after('video_template_url');
            $table->foreignId('product_id')->nullable()->after('video_image_count')->constrained()->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable()->after('product_id');
            // Worker hỏi bài video tới giờ
            $table->index(['type', 'status', 'worker_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropIndex(['type', 'status', 'worker_id', 'scheduled_at']);
            $table->dropConstrainedForeignId('product_id');
            $table->dropConstrainedForeignId('video_template_id');
            $table->dropConstrainedForeignId('worker_id');
            $table->dropColumn(['type', 'video_template_name', 'video_template_url', 'video_image_count', 'scheduled_at']);
        });

        Schema::table('video_templates', function (Blueprint $table) {
            $table->dropColumn('image_count');
        });
    }
};
