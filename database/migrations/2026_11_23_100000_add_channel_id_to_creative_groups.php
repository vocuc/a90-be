<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhóm creative chọn kênh (2026-11-23): channel_id null = dùng chung mọi kênh, có giá trị = chỉ dùng cho kênh đó.
 * Xoá kênh thì nhóm thành dùng chung (nullOnDelete), không mất cấu hình creative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_groups', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creative_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
        });
    }
};
