<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers');
            $table->string('code', 100);
            $table->string('name', 100);
            $table->json('supported_aspect_ratios');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['provider_id', 'code']);
        });

        Schema::create('creator_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('provider_id')->constrained('ai_providers');
            $table->string('label', 100);
            $table->text('api_key'); // mã hóa bằng APP_KEY
            $table->string('key_hint', 10);
            $table->string('status', 20)->default('active');
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_api_keys');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
