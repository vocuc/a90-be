<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lô phối đồ (D:\FlowKit\a51-skills\outfit-video\thiet-ke.txt): generations.kind phân biệt ảnh tạo từ creative
 * (worker loại image nhận) với ảnh bìa / ảnh outfit của lô phối đồ (worker loại outfit nhận).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->string('kind', 20)->default('creative')->after('type');
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropColumn('kind');
        });
    }
};
