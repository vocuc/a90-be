<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Video template của shop (note/quan-ly-video-template.txt ở repo frontend): tên + link video TikTok mẫu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('video_url', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_templates');
    }
};
