<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creative public/private: private chỉ Creator thấy, không hiện trên Marketplace. Creative mới mặc định private;
 * Creative đã có giữ nguyên hiển thị như trước (public).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_creatives', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('status');
        });

        DB::table('ai_creatives')->update(['is_public' => true]);
    }

    public function down(): void
    {
        Schema::table('ai_creatives', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
