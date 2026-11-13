<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gỡ hẳn "kế hoạch nội dung" (code đã đưa về trước commit 9369f94) và xoá toàn bộ dữ liệu của nó.
 * Các migration của kế hoạch (2026_11_12_100000, 2026_11_13_120000/130000/140000) có thể đã chạy trên
 * production nên dọn bằng migration mới, chạy được cả khi chúng CHƯA chạy (kiểm tra bảng / cột trước):
 *  - bài kế hoạch (source plan / status awaiting_approval) + mọi dòng con của chúng;
 *  - ảnh kế hoạch (generations type plan hoặc không có creative) + output + dòng con;
 *  - token kế hoạch (ability planner); social_posts.script / plan_context_id; bảng plan_contexts;
 *  - generations.creative_id về lại NOT NULL;
 *  - tạo lại lookbook_tasks (code hiện tại vẫn dùng) nếu migration gỡ lookbook đã xoá nó.
 */
return new class extends Migration
{
    public function up(): void
    {
        $postIds = DB::table('social_posts')
            ->where(fn ($q) => $q->where('source', 'plan')->orWhere('status', 'awaiting_approval'))
            ->pluck('id')->all();
        $generationIds = DB::table('generations')
            ->where(fn ($q) => $q->where('type', 'plan')->orWhereNull('creative_id'))
            ->pluck('id')->all();

        foreach (array_chunk($postIds, 500) as $ids) {
            $this->deleteChildren('social_posts', $ids);
            DB::table('social_posts')->whereIn('id', $ids)->delete();
        }
        foreach (array_chunk($generationIds, 500) as $ids) {
            $outputIds = DB::table('generation_outputs')->whereIn('generation_id', $ids)->pluck('id')->all();
            foreach (array_chunk($outputIds, 500) as $chunk) {
                $this->deleteChildren('generation_outputs', $chunk);
            }
            $this->deleteChildren('generations', $ids);
            DB::table('generations')->whereIn('id', $ids)->delete();
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('abilities', 'like', '%"planner"%')->delete();
        }

        if (Schema::hasColumn('social_posts', 'plan_context_id')) {
            Schema::table('social_posts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('plan_context_id');
            });
        }
        if (Schema::hasColumn('social_posts', 'script')) {
            Schema::table('social_posts', function (Blueprint $table) {
                $table->dropColumn('script');
            });
        }
        Schema::dropIfExists('plan_contexts');

        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedBigInteger('creative_id')->nullable(false)->change();
        });

        if (! Schema::hasTable('lookbook_tasks')) {
            Schema::create('lookbook_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 20);
                $table->string('image_field', 64);
                $table->json('outfit_ids')->nullable();
                $table->foreignId('social_post_id')->nullable()->constrained('social_posts')->nullOnDelete();
                $table->string('error_code', 50)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['product_id', 'status']);
                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Dữ liệu đã xoá, không khôi phục.
    }

    /** Xoá mọi dòng ở các bảng có khoá ngoại trỏ tới $table.id thuộc $ids (đệ quy một tầng là đủ ở đây). */
    private function deleteChildren(string $table, array $ids): void
    {
        if (! $ids) {
            return;
        }
        foreach ($this->referencing($table) as [$child, $column]) {
            DB::table($child)->whereIn($column, $ids)->delete();
        }
    }

    /** @return array<int, array{0: string, 1: string}> [bảng con, cột] có khoá ngoại tới $table */
    private function referencing(string $table): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return [];
        }

        return collect(DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
            [$table, 'id'],
        ))->map(fn ($row) => [$row->t, $row->c])->all();
    }
};
