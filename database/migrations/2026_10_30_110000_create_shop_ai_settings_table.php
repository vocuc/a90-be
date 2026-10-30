<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cài đặt AI viết nội dung của shop (trang "AI viết nội dung", note/ai-viet-noi-dung-bai-dang.txt ở repo frontend):
 * số ký tự tối đa của tiêu đề / nội dung AI viết và các bài viết mẫu để AI học giọng văn. Kho hashtag ở shop_hashtag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('title_max');
            $table->unsignedSmallInteger('content_max');
            // [{ title, content }]
            $table->json('samples')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_ai_settings');
    }
};
