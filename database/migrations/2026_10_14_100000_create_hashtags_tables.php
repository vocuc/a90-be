<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hashtags', function (Blueprint $table) {
            $table->id();
            // Tên hiển thị lấy theo lần nhập đầu tiên (giữ hoa/thường, dấu), không có dấu #
            $table->string('name', 50);
            // Khóa so trùng: không dấu, chữ thường ("ChânDung" và "chandung" là một hashtag)
            $table->string('slug', 60)->unique();
            $table->timestamps();
        });

        Schema::create('ai_creative_hashtag', function (Blueprint $table) {
            $table->foreignId('creative_id')->constrained('ai_creatives')->cascadeOnDelete();
            $table->foreignId('hashtag_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->primary(['creative_id', 'hashtag_id']);
            $table->index('hashtag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_creative_hashtag');
        Schema::dropIfExists('hashtags');
    }
};
