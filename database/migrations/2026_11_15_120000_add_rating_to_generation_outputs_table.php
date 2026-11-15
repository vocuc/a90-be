<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chủ shop chấm điểm ảnh phối đồ (generations.kind = outfit) theo thang 1-10: điểm càng cao outfit càng hợp shop
 * (yêu cầu người dùng 2026-11-15). null = chưa chấm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('disabled_at');
            $table->timestamp('rated_at')->nullable()->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->dropColumn(['rating', 'rated_at']);
        });
    }
};
