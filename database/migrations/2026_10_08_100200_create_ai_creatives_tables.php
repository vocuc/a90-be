<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users');
            $table->foreignId('category_id')->nullable()->constrained();
            $table->foreignId('model_id')->constrained('ai_models');
            $table->string('title', 150);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('price_per_image')->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('pause_reason', 30)->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->unsignedBigInteger('usage_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'hidden_at', 'category_id']);
            $table->index(['creator_id', 'status']);
        });

        Schema::create('ai_creative_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_id')->constrained('ai_creatives')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_creative_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_id')->unique()->constrained('ai_creatives')->cascadeOnDelete();
            $table->text('system_prompt')->nullable();
            $table->text('creative_prompt')->nullable();
            $table->text('negative_prompt')->nullable();
            $table->unsignedTinyInteger('max_outputs')->default(5);
            $table->json('allowed_aspect_ratios')->nullable(); // null = mọi tỉ lệ model hỗ trợ
            $table->string('default_aspect_ratio', 10)->default('1:1');
            $table->json('extra_params')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_creative_configs');
        Schema::dropIfExists('ai_creative_images');
        Schema::dropIfExists('ai_creatives');
        Schema::dropIfExists('categories');
    }
};
