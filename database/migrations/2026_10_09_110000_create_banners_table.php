<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Banner ảnh trên trang chủ app: bấm vào mở link_url
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100)->nullable();
            $table->string('image_path');
            $table->string('link_url', 500);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
