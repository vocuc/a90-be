<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin gắn 1 tài khoản A51 với 1 worker: worker chỉ nhận job đăng bài của tài khoản A51 đó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
