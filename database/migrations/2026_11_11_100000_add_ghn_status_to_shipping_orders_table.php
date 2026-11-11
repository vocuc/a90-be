<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trạng thái vận chuyển lấy từ GHN (ready_to_pick, delivering, delivered...), khác cột status là trạng thái đơn ở a51.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_orders', function (Blueprint $table) {
            $table->string('ghn_status', 40)->nullable()->after('ghn_order_code');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_orders', function (Blueprint $table) {
            $table->dropColumn('ghn_status');
        });
    }
};
