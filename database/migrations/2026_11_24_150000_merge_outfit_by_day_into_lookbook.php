<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gộp bài viết mẫu "Outfit theo ngày / dịp" (outfit_by_day) vào "Outfit" (lookbook) (người dùng chốt 2026-11-24): chữ trên
 * ảnh outfit thành outfit.texts tuỳ chọn của mọi lô phối đồ. Tuyến nội dung: mục outfit_by_day cộng % vào lookbook (chưa
 * có thì đổi mã); template chữ: outfit_by_day -> lookbook (bỏ trùng). Bài / kế hoạch đã tạo giữ nguyên mã. Không hoàn tác.
 */
return new class extends Migration
{
    private const FROM = 'outfit_by_day';

    private const TO = 'lookbook';

    public function up(): void
    {
        if (Schema::hasTable('channel_lineups')) {
            DB::table('channel_lineups')->where('items', 'like', '%"'.self::FROM.'"%')->orderBy('id')->each(function ($row) {
                $merged = [];
                foreach (json_decode($row->items, true) ?: [] as $item) {
                    $key = ($item['sample_key'] ?? null) === self::FROM ? self::TO : ($item['sample_key'] ?? null);
                    if (isset($merged[$key])) {
                        $merged[$key]['percent'] = round((float) $merged[$key]['percent'] + (float) ($item['percent'] ?? 0), 2);
                    } else {
                        $merged[$key] = [...$item, 'sample_key' => $key];
                    }
                }
                DB::table('channel_lineups')->where('id', $row->id)->update(['items' => json_encode(array_values($merged), JSON_UNESCAPED_UNICODE)]);
            });
        }
        if (Schema::hasTable('channel_text_templates')) {
            DB::table('channel_text_templates')->where('sample_keys', 'like', '%"'.self::FROM.'"%')->orderBy('id')->each(function ($row) {
                $keys = array_map(fn ($k) => $k === self::FROM ? self::TO : $k, json_decode($row->sample_keys, true) ?: []);
                DB::table('channel_text_templates')->where('id', $row->id)->update(['sample_keys' => json_encode(array_values(array_unique($keys)))]);
            });
        }
    }

    public function down(): void {}
};
