<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bài kế hoạch có bước duyệt riêng (awaiting_approval -> duyệt -> pending_review). Bài kế hoạch đang "chờ gửi" mà chưa
 * bấm đăng lần nào thì về chờ duyệt.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('social_posts')
            ->where('source', 'plan')
            ->where('status', 'pending_review')
            ->whereNull('tiktok_status')
            ->whereNull('facebook_status')
            ->whereNull('instagram_status')
            ->update(['status' => 'awaiting_approval']);
    }

    public function down(): void
    {
        DB::table('social_posts')->where('status', 'awaiting_approval')->update(['status' => 'pending_review']);
    }
};
