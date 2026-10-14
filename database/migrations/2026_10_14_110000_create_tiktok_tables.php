<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tài khoản TikTok đã ủy quyền: tối đa 1 dòng / người dùng. Một tài khoản TikTok có thể được nhiều người dùng kết nối.
        Schema::create('tiktok_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('open_id', 100)->index();
            $table->string('union_id', 100)->nullable();
            $table->string('scope', 255);
            $table->text('access_token')->nullable(); // mã hoá
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable(); // mã hoá
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('username', 150)->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('status', 20)->default('active'); // active | expired | revoked
            $table->json('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'refresh_token_expires_at']);
        });

        Schema::create('tiktok_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('generating'); // generating | pending_review | publishing | inbox_delivered | published
            $table->string('source', 10); // new | existing
            $table->string('title', 90)->default('');
            $table->text('content')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('last_error')->nullable();
            // Tài khoản đã dùng để đăng (ảnh chụp lúc bấm đăng)
            $table->foreignId('tiktok_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tiktok_open_id', 100)->nullable();
            $table->string('tiktok_username', 150)->nullable();
            $table->string('tiktok_publish_id', 100)->nullable()->unique();
            $table->string('tiktok_post_id', 100)->nullable();
            $table->string('tiktok_post_url', 500)->nullable();
            $table->string('idempotency_key', 100);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status', 'id']);
        });

        // Ảnh của bài = liên kết tới một output; ảnh / trạng thái đọc từ generation_outputs.
        Schema::create('tiktok_post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiktok_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->constrained();
            $table->foreignId('output_id')->constrained('generation_outputs');
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->index(['tiktok_post_id', 'position']);
            $table->index('output_id');
        });

        // Job viết tiêu đề / nội dung / hashtag cho worker nội bộ: 1 job / bài.
        Schema::create('tiktok_content_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiktok_post_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued'); // queued | processing | completed | failed
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('attempts')->default(0);
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
        Schema::dropIfExists('tiktok_content_jobs');
        Schema::dropIfExists('tiktok_post_images');
        Schema::dropIfExists('tiktok_posts');
        Schema::dropIfExists('tiktok_accounts');
    }
};
