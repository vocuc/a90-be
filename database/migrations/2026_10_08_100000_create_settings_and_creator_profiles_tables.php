<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('creator_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->string('shop_name', 100);
            $table->string('username', 50)->unique();
            $table->string('avatar_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->json('social_links')->nullable();
            $table->boolean('verified')->default(false);
            // null = dùng mức phí mặc định của nền tảng (settings.platform_fee_percent)
            $table->decimal('platform_fee_percent', 5, 2)->nullable();
            $table->unsignedInteger('followers_count')->default(0);
            $table->unsignedInteger('creative_count')->default(0);
            $table->unsignedBigInteger('usage_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_profiles');
        Schema::dropIfExists('settings');
    }
};
