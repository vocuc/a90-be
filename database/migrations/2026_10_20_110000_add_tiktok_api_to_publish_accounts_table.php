<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đăng TikTok qua API song song với worker (note/dang-tiktok-qua-api.txt ở repo frontend):
 * - publish_accounts.driver: worker (admin gắn, như cũ) | api (người dùng tự kết nối TikTok, backend giữ token).
 *   Một tài khoản A51 có tối đa 1 dòng / nền tảng / driver.
 * - social_publish_jobs.driver: chép từ tài khoản lúc tạo job; external_id = publish_id của TikTok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_accounts', function (Blueprint $table) {
            $table->string('driver', 20)->default('worker')->after('platform');
            $table->string('open_id', 100)->nullable()->index()->after('target_url');
            $table->string('scope', 255)->nullable()->after('open_id');
            $table->text('access_token')->nullable()->after('scope');
            $table->timestamp('access_token_expires_at')->nullable()->after('access_token');
            $table->text('refresh_token')->nullable()->after('access_token_expires_at');
            $table->timestamp('refresh_token_expires_at')->nullable()->after('refresh_token');
            $table->string('avatar_url', 1000)->nullable()->after('refresh_token_expires_at');
            $table->string('connection_status', 20)->nullable()->after('avatar_url'); // active | expired | revoked
            $table->json('last_error')->nullable()->after('connection_status');
            $table->timestamp('connected_at')->nullable()->after('last_error');
            $table->timestamp('last_refreshed_at')->nullable()->after('connected_at');
        });
        Schema::table('publish_accounts', function (Blueprint $table) {
            // Index mới có user_id đứng đầu nên khoá ngoại user_id vẫn có index để dùng khi bỏ index cũ
            $table->unique(['user_id', 'platform', 'driver']);
            $table->dropUnique(['user_id', 'platform']);
        });

        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->string('driver', 20)->default('worker')->after('platform');
            $table->string('external_id', 100)->nullable()->index()->after('post_url');
            $table->timestamp('checked_at')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->dropIndex(['external_id']);
            $table->dropColumn(['driver', 'external_id', 'checked_at']);
        });

        Schema::table('publish_accounts', function (Blueprint $table) {
            $table->unique(['user_id', 'platform']);
            $table->dropUnique(['user_id', 'platform', 'driver']);
        });
        Schema::table('publish_accounts', function (Blueprint $table) {
            $table->dropIndex(['open_id']);
            $table->dropColumn([
                'driver', 'open_id', 'scope', 'access_token', 'access_token_expires_at', 'refresh_token',
                'refresh_token_expires_at', 'avatar_url', 'connection_status', 'last_error', 'connected_at', 'last_refreshed_at',
            ]);
        });
    }
};
