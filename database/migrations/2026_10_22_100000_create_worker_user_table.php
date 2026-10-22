<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tài khoản A51 mà worker tạo ảnh nhận job (admin gắn ở trang Worker): worker chỉ nhận job ảnh do các tài khoản này tạo,
 * chưa gắn tài khoản nào thì không nhận job ảnh nào.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_user', function (Blueprint $table) {
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['worker_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_user');
    }
};
