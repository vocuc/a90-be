<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Bỏ bài viết mẫu "shoe_by_outfit" (Chọn giày theo trang phục, người dùng bỏ 2026-11-24): gỡ mã khỏi tuyến nội dung
 * (channel_lineups.items) và danh sách bài viết mẫu của template chữ (channel_text_templates.sample_keys). Bài / kế hoạch đã
 * tạo giữ nguyên mã (lịch sử). Không hoàn tác được.
 */
return new class extends Migration
{
    private const KEY = 'shoe_by_outfit';

    public function up(): void
    {
        if (Schema::hasTable('channel_lineups')) {
            DB::table('channel_lineups')->where('items', 'like', '%"'.self::KEY.'"%')->orderBy('id')->each(function ($row) {
                $items = array_values(array_filter(json_decode($row->items, true) ?: [], fn ($i) => ($i['sample_key'] ?? null) !== self::KEY));
                DB::table('channel_lineups')->where('id', $row->id)->update(['items' => json_encode($items, JSON_UNESCAPED_UNICODE)]);
            });
        }
        if (Schema::hasTable('channel_text_templates')) {
            DB::table('channel_text_templates')->where('sample_keys', 'like', '%"'.self::KEY.'"%')->orderBy('id')->each(function ($row) {
                $keys = array_values(array_diff(json_decode($row->sample_keys, true) ?: [], [self::KEY]));
                DB::table('channel_text_templates')->where('id', $row->id)->update(['sample_keys' => json_encode($keys)]);
            });
        }
    }

    public function down(): void {}
};
