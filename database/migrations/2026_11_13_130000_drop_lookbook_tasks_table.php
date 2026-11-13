<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ luồng lookbook riêng (skill xin sản phẩm qua /lookbook-tasks): lookbook sau này đi qua kế hoạch nội dung.
 * Bài lookbook cũ (source lookbook, ảnh upload thẳng ở social_post_images.image_path) giữ nguyên như bài thường.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lookbook_tasks');
    }

    public function down(): void
    {
        Schema::create('lookbook_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
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
    }
};
