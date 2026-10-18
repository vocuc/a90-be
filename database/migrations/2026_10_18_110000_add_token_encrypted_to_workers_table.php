<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token mã hoá (APP_KEY) để admin xem lại ở trang Worker; xác thực vẫn dùng token_hash.
        // Worker tạo trước khi có cột này: null, admin tạo token mới mới xem được.
        Schema::table('workers', function (Blueprint $table) {
            $table->text('token_encrypted')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('token_encrypted');
        });
    }
};
