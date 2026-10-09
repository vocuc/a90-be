<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bỏ trạng thái archived: Creative đang lưu trữ chuyển sang tạm dừng thủ công.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_creatives')->where('status', 'archived')
            ->update(['status' => 'paused', 'pause_reason' => 'manual', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Không khôi phục được: không còn biết Creative nào từng lưu trữ.
    }
};
