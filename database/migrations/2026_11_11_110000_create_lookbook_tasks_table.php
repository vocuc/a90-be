<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skill tạo lookbook (FlowKit a51-skills/tao-anh-look-book/thiet-ke.txt):
 * - lookbook_tasks: lịch sử tạo lookbook của từng sản phẩm (A51 giao sản phẩm, skill báo xong / lỗi).
 * - social_post_images: ảnh upload thẳng (ảnh lookbook từ ChatGPT, không qua lượt tạo ảnh của A51) lưu ở image_path /
 *   thumb_path, khi đó generation_id / output_id = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookbook_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20); // processing | completed | failed
            $table->string('image_field', 64);
            $table->json('outfit_ids')->nullable();
            $table->foreignId('social_post_id')->nullable()->constrained('social_posts')->nullOnDelete();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('social_post_images', function (Blueprint $table) {
            $table->unsignedBigInteger('generation_id')->nullable()->change();
            $table->unsignedBigInteger('output_id')->nullable()->change();
            $table->string('image_path')->nullable()->after('output_id');
            $table->string('thumb_path')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_images', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'thumb_path']);
        });
        Schema::dropIfExists('lookbook_tasks');
    }
};
