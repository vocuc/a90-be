<?php

use App\Support\ProductTags;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tag kieu sản phẩm đổi sang hashtag loại giày dùng chung với OOTD (ProductTags::SHOE_TYPES, người dùng chốt 2026-11-24):
 * cao_got -> giaycaogot, sneaker -> giaythethao, dep -> giaydep... Bỏ tag ootd (hashtag giày OOTD riêng, không dùng nữa).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->whereNotNull('tags')->orderBy('id')->each(function ($row) {
            $tags = json_decode($row->tags, true) ?: [];
            $sources = json_decode($row->tag_sources ?? 'null', true) ?: [];
            if (isset($tags['kieu'])) {
                $tags['kieu'] = ProductTags::shoeType((string) $tags['kieu']);
            }
            unset($tags['ootd'], $sources['ootd']);
            DB::table('products')->where('id', $row->id)->update([
                'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
                'tag_sources' => $sources ? json_encode($sources) : null,
            ]);
        });
    }

    public function down(): void
    {
        // Không đổi ngược: hashtag mới vẫn đọc được kieu cũ qua cung_nghia
    }
};
