<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ Credit trên toàn A51 (a90-fe note/bo-credit.txt): mọi thao tác miễn phí, không còn ví, sổ cái, giao dịch,
 * doanh thu Creator, giá Creative. Xoá hẳn dữ liệu (Admin chốt 2026-11-13), không khôi phục được.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('creator_revenues');

        if (Schema::hasColumn('generation_outputs', 'transaction_id')) {
            Schema::table('generation_outputs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('transaction_id');
            });
        }

        Schema::dropIfExists('transactions');
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('credit_wallets');
        Schema::dropIfExists('creator_wallets');

        foreach ([
            'ai_creatives' => 'price_per_image',
            'generations' => 'price_per_image',
            'social_content_jobs' => 'charge_amount',
            'creator_profiles' => 'creator_share_percent',
        ] as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }

        DB::table('settings')->whereIn('key', ['creator_share_percent', 'revenue_hold_days', 'social.ai_content_price'])->delete();
    }

    public function down(): void
    {
        // Dữ liệu Credit đã xoá, không khôi phục.
    }
};
