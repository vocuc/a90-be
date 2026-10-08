<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('creative_id')->constrained('ai_creatives');
            $table->foreignId('creator_id')->constrained('users');
            $table->foreignId('model_id')->constrained('ai_models');
            $table->foreignId('parent_generation_id')->nullable()->constrained('generations');
            $table->string('type', 10); // paid | owner | test
            $table->string('input_image_path');
            $table->string('aspect_ratio', 10);
            $table->unsignedTinyInteger('quantity');
            $table->unsignedInteger('price_per_image');
            $table->text('prompt_snapshot');
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('success_count')->default(0);
            $table->unsignedTinyInteger('failed_count')->default(0);
            $table->string('idempotency_key', 100);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('creator_id')->constrained('users');
            $table->foreignId('creative_id')->constrained('ai_creatives');
            $table->foreignId('generation_id')->constrained();
            $table->unsignedBigInteger('generation_output_id')->unique();
            $table->string('model_code', 100);
            $table->unsignedInteger('amount');
            $table->decimal('platform_fee_percent', 5, 2);
            $table->unsignedInteger('platform_fee_amount');
            $table->unsignedInteger('creator_amount');
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['creator_id', 'status']);
        });

        Schema::create('generation_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('retry_of_output_id')->nullable()->constrained('generation_outputs');
            $table->foreignId('transaction_id')->nullable()->unique()->constrained();
            $table->foreignId('api_key_id')->nullable()->constrained('creator_api_keys');
            $table->string('status', 20)->default('queued');
            $table->string('image_path')->nullable();
            $table->string('mime', 50)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('generation_output_id')->references('id')->on('generation_outputs');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['generation_output_id']);
        });
        Schema::dropIfExists('generation_outputs');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('generations');
    }
};
