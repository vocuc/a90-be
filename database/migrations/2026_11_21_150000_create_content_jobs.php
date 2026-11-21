<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content-worker dựng nội dung bài ảnh (a90-fe note/xay-kenh-tiktok/worker-content.txt, người dùng chốt 2026-11-21):
 * - text_overlay_jobs -> content_jobs (+ sample_key): job giao CONTENT-WORKER của shop (không còn là worker đăng bài).
 * - social_post_images.added_by_worker: slide worker thêm (vd bìa ghép top_n), làm lại bài thì bỏ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('text_overlay_jobs', 'content_jobs');
        Schema::table('content_jobs', function (Blueprint $table) {
            $table->string('sample_key', 50)->nullable()->after('worker_id');
        });
        Schema::table('social_post_images', function (Blueprint $table) {
            $table->boolean('added_by_worker')->default(false)->after('overlay_status');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_images', function (Blueprint $table) {
            $table->dropColumn('added_by_worker');
        });
        Schema::table('content_jobs', function (Blueprint $table) {
            $table->dropColumn('sample_key');
        });
        Schema::rename('content_jobs', 'text_overlay_jobs');
    }
};
