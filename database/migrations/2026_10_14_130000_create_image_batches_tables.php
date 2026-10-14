<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tách "Tạo ảnh theo lô" khỏi bài đăng TikTok (note/tach-lo-anh-dang-tiktok.txt). */
return new class extends Migration
{
    public function up(): void
    {
        // Lô ảnh: trạng thái tính từ ảnh (còn ảnh queued / processing = đang tạo)
        Schema::create('image_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'id']);
        });

        // Ảnh của lô = liên kết tới một output; ảnh / trạng thái đọc từ generation_outputs.
        Schema::create('image_batch_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->constrained();
            $table->foreignId('output_id')->constrained('generation_outputs');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['image_batch_id', 'position']);
            $table->index('output_id');
        });

        Schema::table('tiktok_posts', function (Blueprint $table) {
            // Lô đã tạo ra bài (null: bài từ ảnh đã có / lô đã xoá)
            $table->foreignId('image_batch_id')->nullable()->after('source')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_batch_id');
        });
        Schema::dropIfExists('image_batch_images');
        Schema::dropIfExists('image_batches');
    }
};
