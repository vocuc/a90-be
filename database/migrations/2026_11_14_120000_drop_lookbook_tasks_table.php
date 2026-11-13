<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Gỡ luồng lookbook (API /lookbook-tasks), chờ thiết kế lại. Bài lookbook cũ (source lookbook, ảnh upload thẳng ở
 * social_post_images.image_path) giữ nguyên như bài thường.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lookbook_tasks');
    }

    public function down(): void
    {
        // Không tạo lại: code lookbook đã gỡ.
    }
};
