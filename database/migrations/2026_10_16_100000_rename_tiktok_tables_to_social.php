<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đăng bài lên nhiều nền tảng (note/dang-bai-da-nen-tang.txt ở repo frontend): bỏ tên TikTok khỏi phần bài đăng.
 *   tiktok_posts -> social_posts (+ tiktok_status / facebook_status / instagram_status, bỏ tiktok_account_id)
 *   tiktok_post_images -> social_post_images, tiktok_content_jobs -> social_content_jobs
 *   tiktok_accounts -> publish_accounts (+ platform, target_url; 1 tài khoản A51 có tối đa 1 tài khoản / nền tảng)
 *   tiktok_publish_jobs -> social_publish_jobs (+ platform, post_url)
 * Cột tiktok_post_id -> social_post_id, tiktok_account_id -> publish_account_id. Giữ nguyên dữ liệu.
 * MySQL giữ tên khoá ngoại / index cũ khi đổi tên bảng: xoá khoá ngoại rồi tạo lại, đổi tên index theo tên mới.
 * (social_accounts đã dùng cho đăng nhập Google nên tài khoản đăng bài tên là publish_accounts.)
 */
return new class extends Migration
{
    private const TABLES = [
        'tiktok_accounts' => 'publish_accounts',
        'tiktok_posts' => 'social_posts',
        'tiktok_post_images' => 'social_post_images',
        'tiktok_content_jobs' => 'social_content_jobs',
        'tiktok_publish_jobs' => 'social_publish_jobs',
    ];

    private const COLUMNS = [
        'tiktok_post_id' => 'social_post_id',
        'tiktok_account_id' => 'publish_account_id',
    ];

    public function up(): void
    {
        // Trạng thái TikTok của bài cũ, tính trước khi đổi cấu trúc: đã bấm đăng (có job) thì có trạng thái
        $statuses = DB::table('tiktok_posts')
            ->whereExists(fn ($q) => $q->from('tiktok_publish_jobs')->whereColumn('tiktok_publish_jobs.tiktok_post_id', 'tiktok_posts.id'))
            ->pluck('status', 'id')
            ->map(fn ($status) => match ($status) {
                'queued', 'publishing', 'published' => $status,
                default => 'failed', // đã bấm đăng mà về chờ gửi: lần đăng gần nhất lỗi / quá hạn
            });

        $foreignKeys = $this->renameTables(self::TABLES, self::COLUMNS);

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('publish_account_id');
            $table->string('tiktok_status', 20)->nullable()->after('status');
            $table->string('facebook_status', 20)->nullable()->after('tiktok_status');
            $table->string('instagram_status', 20)->nullable()->after('facebook_status');
        });
        foreach ($statuses->groupBy(fn ($status) => $status, true) as $status => $ids) {
            DB::table('social_posts')->whereIn('id', $ids->keys())->update(['tiktok_status' => $status]);
        }

        Schema::table('publish_accounts', function (Blueprint $table) {
            $table->string('platform', 20)->default('tiktok')->after('user_id');
            // Facebook: trang / nhóm / trang cá nhân worker đăng bài vào
            $table->string('target_url', 500)->nullable()->after('username');
            // Index mới bắt đầu bằng user_id nên khoá ngoại user_id vẫn có index khi bỏ unique cũ
            $table->unique(['user_id', 'platform']);
            $table->dropUnique(['user_id']);
        });

        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->string('platform', 20)->default('tiktok')->after('social_post_id');
            $table->string('post_url', 500)->nullable()->after('publish_account_id');
            // Worker nhận job chờ cũ nhất của nền tảng; trang bài lấy job gần nhất của từng nền tảng
            $table->index(['status', 'platform', 'id']);
            $table->index(['social_post_id', 'platform', 'id']);
        });

        $this->addForeignKeys(array_filter($foreignKeys, fn ($fk) => ! ($fk['table'] === 'social_posts' && $fk['columns'] === ['publish_account_id'])));
    }

    public function down(): void
    {
        // Chỉ giữ được TikTok: bỏ tài khoản / job của nền tảng khác
        DB::table('social_publish_jobs')->where('platform', '!=', 'tiktok')->delete();
        DB::table('publish_accounts')->where('platform', '!=', 'tiktok')->delete();
        DB::table('social_posts')->where('status', 'published')->where('tiktok_status', '!=', 'published')->update(['status' => 'pending_review']);

        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->dropIndex(['status', 'platform', 'id']);
            $table->dropIndex(['social_post_id', 'platform', 'id']);
        });
        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->dropColumn(['platform', 'post_url']);
        });

        Schema::table('publish_accounts', function (Blueprint $table) {
            $table->unique('user_id');
        });
        Schema::table('publish_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'platform']);
            $table->dropColumn(['platform', 'target_url']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['tiktok_status', 'facebook_status', 'instagram_status']);
            $table->foreignId('publish_account_id')->nullable()->after('last_error');
        });

        $foreignKeys = $this->renameTables(array_flip(self::TABLES), array_flip(self::COLUMNS));
        $foreignKeys[] = ['table' => 'tiktok_posts', 'columns' => ['tiktok_account_id'], 'foreign_table' => 'tiktok_accounts', 'foreign_columns' => ['id'], 'on_delete' => 'set null'];
        $this->addForeignKeys($foreignKeys);
    }

    /**
     * Xoá khoá ngoại của các bảng, đổi tên bảng / cột / index. Trả về khoá ngoại (theo tên mới) để tạo lại sau.
     *
     * @param  array<string, string>  $tables
     * @param  array<string, string>  $columns
     * @return array<int, array{table: string, columns: string[], foreign_table: string, foreign_columns: string[], on_delete: ?string}>
     */
    private function renameTables(array $tables, array $columns): array
    {
        $rename = fn (array $names) => array_map(fn ($name) => $columns[$name] ?? $name, $names);

        $foreignKeys = [];
        foreach (array_keys($tables) as $from) {
            foreach (Schema::getForeignKeys($from) as $fk) {
                $foreignKeys[] = [
                    'table' => $tables[$from],
                    'columns' => $rename($fk['columns']),
                    'foreign_table' => $tables[$fk['foreign_table']] ?? $fk['foreign_table'],
                    'foreign_columns' => $fk['foreign_columns'],
                    'on_delete' => $fk['on_delete'],
                ];
                Schema::table($from, fn (Blueprint $table) => $table->dropForeign($fk['name']));
            }
        }

        foreach ($tables as $from => $to) {
            Schema::rename($from, $to);
            Schema::table($to, function (Blueprint $table) use ($to, $columns) {
                foreach ($columns as $old => $new) {
                    if (Schema::hasColumn($to, $old)) {
                        $table->renameColumn($old, $new);
                    }
                }
            });

            // Index tên cũ (tiktok_posts_user_id_status_id_index...) -> tên mới
            $indexes = collect(Schema::getIndexes($to))->reject(fn ($index) => $index['primary']);
            Schema::table($to, function (Blueprint $table) use ($indexes, $from, $to, $columns) {
                foreach ($indexes as $index) {
                    $name = str_replace(array_keys($columns), array_values($columns), str_replace($from.'_', $to.'_', $index['name']));
                    if ($name !== $index['name']) {
                        $table->renameIndex($index['name'], $name);
                    }
                }
            });
        }

        return $foreignKeys;
    }

    /** @param  array<int, array{table: string, columns: string[], foreign_table: string, foreign_columns: string[], on_delete: ?string}>  $foreignKeys */
    private function addForeignKeys(array $foreignKeys): void
    {
        foreach ($foreignKeys as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk) {
                $foreign = $table->foreign($fk['columns'])->references($fk['foreign_columns'])->on($fk['foreign_table']);
                if ($fk['on_delete'] && $fk['on_delete'] !== 'no action' && $fk['on_delete'] !== 'restrict') {
                    $foreign->onDelete($fk['on_delete']);
                }
            });
        }
    }
};
