<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gộp MỌI việc của worker vào 1 bảng worker_jobs phân biệt bằng type (a90-fe note/gop-viec-worker.txt, chốt 2026-11-21):
 * image (ảnh Creative), outfit (ảnh lô phối đồ), content (dựng bài ảnh), publish (đăng bài).
 * - generation_outputs giữ vai trò ẢNH (đường dẫn + status cho giao diện), bỏ cột hàng đợi (worker_id, attempts,
 *   lease_expires_at, started_at) -> worker_jobs.
 * - content_jobs, social_publish_jobs: chuyển việc CHƯA XONG sang worker_jobs rồi xoá bảng. Riêng đăng bài giữ thêm job
 *   gần nhất của mỗi bài (link bài đã đăng / trạng thái từng nền tảng giao diện đang đọc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_jobs', function (Blueprint $table) {
            $table->id();
            // image | outfit | content | publish (= loại worker nhận)
            $table->string('type', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Worker người dùng chọn (generations.worker_id); worker đó tắt / gỡ -> worker khác cùng loại nhận
            $table->unsignedBigInteger('preferred_worker_id')->nullable();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generation_output_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_id')->nullable()->constrained()->cascadeOnDelete();
            // queued | processing | completed | failed | superseded
            $table->string('status', 20);
            // Đăng bài: nền tảng của lần nhận; dựng bài: dạng bài lúc tạo việc
            $table->json('platforms')->nullable();
            $table->string('sample_key', 50)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('post_url', 500)->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
            $table->index(['worker_id', 'status']);
        });

        $now = now();
        // Ảnh chưa xong -> việc image / outfit
        DB::table('generation_outputs as o')->join('generations as g', 'g.id', '=', 'o.generation_id')
            ->whereIn('o.status', ['queued', 'processing'])
            ->orderBy('o.id')
            ->select('o.id', 'o.status', 'o.worker_id', 'o.attempts', 'o.lease_expires_at', 'o.started_at', 'g.user_id', 'g.worker_id as preferred', 'g.kind')
            ->chunk(500, function ($rows) use ($now) {
                DB::table('worker_jobs')->insert($rows->map(fn ($r) => [
                    'type' => in_array($r->kind, ['outfit', 'outfit_cover'], true) ? 'outfit' : 'image',
                    'user_id' => $r->user_id, 'preferred_worker_id' => $r->preferred, 'worker_id' => $r->worker_id,
                    'generation_output_id' => $r->id, 'status' => $r->status, 'attempts' => $r->attempts,
                    'lease_expires_at' => $r->lease_expires_at, 'claimed_at' => $r->started_at,
                    'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });

        if (Schema::hasTable('content_jobs')) {
            DB::table('content_jobs as j')->join('social_posts as p', 'p.id', '=', 'j.social_post_id')
                ->whereIn('j.status', ['queued', 'processing'])->orderBy('j.id')
                ->select('j.*', 'p.user_id')
                ->chunk(500, fn ($rows) => DB::table('worker_jobs')->insert($rows->map(fn ($r) => [
                    'type' => 'content', 'user_id' => $r->user_id, 'worker_id' => $r->worker_id, 'social_post_id' => $r->social_post_id,
                    'status' => $r->status, 'sample_key' => $r->sample_key, 'claimed_at' => $r->claimed_at,
                    'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                ])->all()));
            Schema::drop('content_jobs');
        }

        if (Schema::hasTable('social_publish_jobs')) {
            $latest = DB::table('social_publish_jobs')->groupBy('social_post_id')->selectRaw('MAX(id)');
            DB::table('social_publish_jobs as j')->join('social_posts as p', 'p.id', '=', 'j.social_post_id')
                ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('j.status', ['queued', 'processing'])->where('j.superseded', false))
                    ->orWhereIn('j.id', $latest))
                ->orderBy('j.id')
                ->select('j.*', 'p.user_id')
                ->chunk(500, fn ($rows) => DB::table('worker_jobs')->insert($rows->map(fn ($r) => [
                    'type' => 'publish', 'user_id' => $r->user_id, 'worker_id' => $r->worker_id, 'social_post_id' => $r->social_post_id,
                    'status' => $r->superseded ? 'superseded' : $r->status, 'platforms' => $r->platforms, 'post_url' => $r->post_url,
                    'error_code' => $r->error_code, 'error_message' => $r->error_message, 'finished_at' => $r->finished_at,
                    'claimed_at' => $r->created_at, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                ])->all()));
            Schema::drop('social_publish_jobs');
        }

        Schema::table('generation_outputs', function (Blueprint $table) {
            if (Schema::hasColumn('generation_outputs', 'worker_id')) {
                $table->dropConstrainedForeignId('worker_id');
            }
        });
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropColumn(['attempts', 'lease_expires_at', 'started_at']);
        });
    }

    public function down(): void
    {
        // Không khôi phục dữ liệu việc cũ (chỉ dựng lại cấu trúc)
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->foreignId('worker_id')->nullable()->after('retry_of_output_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->timestamp('lease_expires_at')->nullable()->after('attempts');
            $table->timestamp('started_at')->nullable()->after('error_message');
            $table->index(['status', 'lease_expires_at']);
        });
        Schema::create('content_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sample_key', 50)->nullable();
            $table->string('status', 20);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('social_publish_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->json('platforms')->nullable();
            $table->string('status', 20);
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('superseded')->default(false);
            $table->string('post_url', 500)->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::dropIfExists('worker_jobs');
    }
};
