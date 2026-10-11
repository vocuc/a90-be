<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đổi cách cấu hình chia tiền: con số % là phần Creator nhận, không còn là phí nền tảng.
 * - settings / creator_profiles: giữ nguyên con số, chỉ đổi ý nghĩa.
 * - transactions: snapshot cũ đổi thành 100 − phí để khớp số tiền đã chia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'platform_fee_percent')->update(['key' => 'creator_share_percent']);

        Schema::table('creator_profiles', function (Blueprint $table) {
            $table->renameColumn('platform_fee_percent', 'creator_share_percent');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('platform_fee_percent', 'creator_share_percent');
        });
        DB::table('transactions')->update(['creator_share_percent' => DB::raw('100 - creator_share_percent')]);
    }

    public function down(): void
    {
        DB::table('transactions')->update(['creator_share_percent' => DB::raw('100 - creator_share_percent')]);
        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('creator_share_percent', 'platform_fee_percent');
        });

        Schema::table('creator_profiles', function (Blueprint $table) {
            $table->renameColumn('creator_share_percent', 'platform_fee_percent');
        });

        DB::table('settings')->where('key', 'creator_share_percent')->update(['key' => 'platform_fee_percent']);
    }
};
