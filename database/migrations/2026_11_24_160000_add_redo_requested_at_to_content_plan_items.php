<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nút "Làm lại" cho bài trong kỳ CHƯA DUYỆT (người dùng 2026-11-24): chủ shop đánh dấu bài -> worker điều phối chạy
 * /lap-ke-hoach --lam-lai soạn lại bài đó (cùng giờ đăng, cùng định dạng). Cờ xoá khi lệnh gửi bài mới.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plan_items', function (Blueprint $table) {
            $table->timestamp('redo_requested_at')->nullable()->after('hook');
        });
    }

    public function down(): void
    {
        Schema::table('content_plan_items', function (Blueprint $table) {
            $table->dropColumn('redo_requested_at');
        });
    }
};
