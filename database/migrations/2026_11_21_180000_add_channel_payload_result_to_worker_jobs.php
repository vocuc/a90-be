<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Việc "xây dựng chủ đề tự động" của kênh (worker_jobs type topic, a90-fe note/xay-kenh-tiktok/worker-lap-chu-de.txt):
 * channel_id = kênh, payload = yêu cầu chủ shop nhập ({count, note}), result = kết quả ({created, skipped}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_jobs', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('social_post_id')->constrained()->cascadeOnDelete();
            $table->json('payload')->nullable()->after('sample_key');
            $table->json('result')->nullable()->after('post_url');
        });
    }

    public function down(): void
    {
        Schema::table('worker_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
            $table->dropColumn(['payload', 'result']);
        });
    }
};
