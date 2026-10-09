<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo ảnh qua worker nội bộ thay cho gọi Gemini bằng API key của Creator:
 * thêm bảng workers, bỏ creator_api_keys và lý do tự tạm dừng Creative.
 */
return new class extends Migration
{
    /** Mỗi bước tự bỏ qua nếu đã chạy: MySQL không rollback DDL nên lần chạy lỗi giữa chừng có thể chạy lại. */
    public function up(): void
    {
        if (! Schema::hasTable('workers')) {
            Schema::create('workers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->char('token_hash', 64)->unique(); // sha256 của token
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('generation_outputs', 'api_key_id')) {
            Schema::table('generation_outputs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('api_key_id');
            });
        }

        if (! Schema::hasColumn('generation_outputs', 'worker_id')) {
            Schema::table('generation_outputs', function (Blueprint $table) {
                $table->foreignId('worker_id')->nullable()->after('transaction_id')->constrained()->nullOnDelete();
                $table->unsignedTinyInteger('attempts')->default(0)->after('status');
                $table->timestamp('lease_expires_at')->nullable()->after('attempts');

                $table->index(['status', 'lease_expires_at']);
            });
        }

        // Log gọi Gemini (migration tạo bảng đã xóa) có khóa ngoại tới creator_api_keys nên phải xóa trước.
        Schema::dropIfExists('ai_provider_calls');
        Schema::dropIfExists('creator_api_keys');

        if (Schema::hasColumn('ai_creatives', 'pause_reason')) {
            Schema::table('ai_creatives', function (Blueprint $table) {
                $table->dropColumn('pause_reason');
            });
        }

        DB::table('settings')->where('key', 'api_key.cooldown_minutes')->delete();
    }

    public function down(): void
    {
        Schema::table('ai_creatives', function (Blueprint $table) {
            $table->string('pause_reason', 30)->nullable()->after('status');
        });

        Schema::create('creator_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('provider_id')->constrained('ai_providers');
            $table->string('label', 100);
            $table->text('api_key');
            $table->string('key_hint', 10);
            $table->string('status', 20)->default('active');
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'provider_id', 'status']);
        });

        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropConstrainedForeignId('worker_id');
            $table->dropColumn(['attempts', 'lease_expires_at']);
            $table->foreignId('api_key_id')->nullable()->after('transaction_id')->constrained('creator_api_keys');
        });

        Schema::dropIfExists('workers');
    }
};
