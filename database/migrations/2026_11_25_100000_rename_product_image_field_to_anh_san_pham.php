<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Khung "Ảnh sản phẩm" cố định anh_san_pham (người dùng chốt 2026-11-25: "đổi hết product_image thành anh_san_pham").
 * Đổi key trường ảnh product_image -> anh_san_pham ở:
 *  - product_images.field_key (sản phẩm đã có ảnh anh_san_pham thì giữ nguyên ảnh product_image, tránh trùng key);
 *  - ai_creative_configs.input_schema (key trường; Creative đã có trường anh_san_pham thì bỏ qua);
 *  - generations.input_paths / asset_paths (khoá JSON, để "tạo lại" ảnh cũ khớp trường mới).
 * KHÔNG sửa prompt_template (người dùng chốt): biến {{product_image}} là ảnh, lúc render vốn đã thành rỗng.
 * Worker tạo ảnh nhận khoá anhSanPham thay cho productImage (tool Flow đọc khoá tuỳ ý).
 */
return new class extends Migration
{
    private const OLD = 'product_image';

    private const NEW = 'anh_san_pham';

    public function up(): void
    {
        $this->renameProductImages(self::OLD, self::NEW);
        $this->renameSchemas(self::OLD, self::NEW);
        $this->renameGenerationPaths(self::OLD, self::NEW);
    }

    public function down(): void
    {
        // Không phân biệt được ảnh vốn là anh_san_pham với ảnh đã đổi -> không hoàn tác.
    }

    private function renameProductImages(string $old, string $new): void
    {
        // MySQL không cho UPDATE bảng có subquery tới chính nó -> lấy trước sản phẩm đã có ảnh key mới
        $hasNew = DB::table('product_images')->where('field_key', $new)->pluck('product_id')->all();
        DB::table('product_images')->where('field_key', $old)->whereNotIn('product_id', $hasNew)->update(['field_key' => $new]);
    }

    private function renameSchemas(string $old, string $new): void
    {
        DB::table('ai_creative_configs')->where('input_schema', 'like', '%"'.$old.'"%')->orderBy('id')
            ->chunkById(200, function ($rows) use ($old, $new) {
                foreach ($rows as $row) {
                    $fields = json_decode($row->input_schema, true);
                    if (! is_array($fields)) {
                        continue;
                    }
                    $keys = array_column(array_filter($fields, 'is_array'), 'key');
                    if (! in_array($old, $keys, true) || in_array($new, $keys, true)) {
                        continue;
                    }
                    foreach ($fields as &$field) {
                        if (is_array($field) && ($field['key'] ?? null) === $old) {
                            $field['key'] = $new;
                        }
                    }
                    unset($field);
                    DB::table('ai_creative_configs')->where('id', $row->id)
                        ->update(['input_schema' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            });
    }

    private function renameGenerationPaths(string $old, string $new): void
    {
        DB::table('generations')
            ->where(fn ($q) => $q->where('input_paths', 'like', '%"'.$old.'"%')->orWhere('asset_paths', 'like', '%"'.$old.'"%'))
            ->orderBy('id')->select(['id', 'input_paths', 'asset_paths'])
            ->chunkById(500, function ($rows) use ($old, $new) {
                foreach ($rows as $row) {
                    $changes = [];
                    foreach (['input_paths', 'asset_paths'] as $column) {
                        $paths = $row->{$column} === null ? null : json_decode($row->{$column}, true);
                        if (is_array($paths) && array_key_exists($old, $paths) && ! array_key_exists($new, $paths)) {
                            $renamed = [];
                            foreach ($paths as $key => $path) {
                                $renamed[$key === $old ? $new : $key] = $path;
                            }
                            $changes[$column] = json_encode($renamed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    }
                    if ($changes) {
                        DB::table('generations')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }
};
