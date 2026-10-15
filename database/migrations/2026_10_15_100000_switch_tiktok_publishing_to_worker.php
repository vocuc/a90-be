<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đăng TikTok qua worker (note/dang-tiktok-qua-worker.txt ở repo frontend): bỏ ủy quyền TikTok của người dùng,
 * tài khoản TikTok do A51 quản lý (mỗi tài khoản do 1 worker giữ), bấm đăng tạo job cho worker lưu nháp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_posts', function (Blueprint $table) {
            $table->dropForeign(['tiktok_account_id']);
            $table->dropUnique(['tiktok_publish_id']);
            $table->dropColumn(['tiktok_open_id', 'tiktok_username', 'tiktok_publish_id', 'tiktok_post_id', 'tiktok_post_url']);
        });

        // Bài đang gửi qua API không còn theo dõi được: về chờ gửi; đã vào hộp thư TikTok coi như đã xong.
        DB::table('tiktok_posts')->update(['tiktok_account_id' => null]);
        DB::table('tiktok_posts')->where('status', 'publishing')->update(['status' => 'pending_review']);
        DB::table('tiktok_posts')->where('status', 'inbox_delivered')->update(['status' => 'published']);

        Schema::dropIfExists('tiktok_accounts');

        // Tài khoản TikTok của A51, admin quản lý. worker_id: worker đang giữ (đã đăng nhập sẵn) tài khoản này.
        Schema::create('tiktok_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('username', 100)->nullable();
            $table->foreignId('worker_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('tiktok_posts', function (Blueprint $table) {
            // Tài khoản của worker đã nhận job đăng gần nhất
            $table->foreign('tiktok_account_id')->references('id')->on('tiktok_accounts')->nullOnDelete();
        });

        // Job đăng bài cho worker: mỗi lần bấm đăng 1 job. Lỗi / quá hạn không giao lại, bài về chờ gửi.
        Schema::create('tiktok_publish_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiktok_post_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued'); // queued | processing | completed | failed
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tiktok_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_publish_jobs');

        Schema::table('tiktok_posts', function (Blueprint $table) {
            $table->dropForeign(['tiktok_account_id']);
        });
        DB::table('tiktok_posts')->update(['tiktok_account_id' => null]);
        DB::table('tiktok_posts')->where('status', 'queued')->update(['status' => 'pending_review']);

        Schema::dropIfExists('tiktok_accounts');
        Schema::create('tiktok_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('open_id', 100)->index();
            $table->string('union_id', 100)->nullable();
            $table->string('scope', 255);
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('username', 150)->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('status', 20)->default('active');
            $table->json('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'refresh_token_expires_at']);
        });

        Schema::table('tiktok_posts', function (Blueprint $table) {
            $table->foreign('tiktok_account_id')->references('id')->on('tiktok_accounts')->nullOnDelete();
            $table->string('tiktok_open_id', 100)->nullable();
            $table->string('tiktok_username', 150)->nullable();
            $table->string('tiktok_publish_id', 100)->nullable()->unique();
            $table->string('tiktok_post_id', 100)->nullable();
            $table->string('tiktok_post_url', 500)->nullable();
        });
    }
};
