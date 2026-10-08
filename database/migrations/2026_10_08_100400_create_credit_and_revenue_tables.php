<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });

        // Sổ cái Credit: chỉ thêm, không sửa, không xóa.
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type', 30);
            $table->bigInteger('amount'); // âm = trừ, dương = cộng
            $table->bigInteger('balance_after');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 150)->nullable()->unique();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('creator_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->unsignedBigInteger('pending_balance')->default(0);
            $table->unsignedBigInteger('available_balance')->default(0);
            $table->unsignedBigInteger('withdrawable_balance')->default(0);
            $table->unsignedBigInteger('total_earned')->default(0);
            $table->timestamps();
        });

        Schema::create('creator_revenues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users');
            $table->foreignId('transaction_id')->unique()->constrained();
            $table->unsignedInteger('amount');
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['creator_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('action', 50);
            $table->string('resource_type', 50)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['resource_type', 'resource_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('creator_revenues');
        Schema::dropIfExists('creator_wallets');
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('credit_wallets');
    }
};
