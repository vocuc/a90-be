<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Tag sản phẩm (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt mục 2). AI gắn bằng skill /gan-tag-san-pham, chủ
 * shop sửa được; mỗi trường có nguồn (tag_sources) ai | owner: AI chỉ ghi trường do AI đặt hoặc chưa có.
 * Dùng cho cả bộ lọc tag của chủ đề kênh (channel_topics.tags).
 */
class ProductTags
{
    public const SOURCE_AI = 'ai';

    public const SOURCE_OWNER = 'owner';

    public const LABEL_MAX = 60;

    /** Trường 1 giá trị (enum). */
    public const SINGLE = [
        'kieu' => ['cao_got', 'sandal', 'bup_be', 'mule', 'sneaker', 'boot', 'dep', 'slingback'],
        'got' => ['bet', 'thap', 'vua', 'cao'],
    ];

    /** Trường nhiều giá trị: [giá trị cho phép (null = chữ tự do), tối đa]. */
    public const MULTI = [
        'mau' => [null, 2],
        'phong_cach' => [['sang_chanh', 'nhe_nhang', 'tre_trung', 'ca_tinh', 'basic'], 3],
        'dip' => [['di_lam', 'di_tiec', 'tet', 'hen_ho', 'di_choi', 'cafe'], 4],
    ];

    public const MAU_MAX = 20;

    /** Giá trị cho phép từng trường (frontend dựng ô chọn; null = chữ tự do). */
    public static function options(): array
    {
        return [
            ...self::SINGLE,
            ...array_map(fn ($m) => $m[0], self::MULTI),
        ];
    }

    /** Mọi trường có nguồn: ai_label + các tag. */
    public static function fields(): array
    {
        return ['ai_label', ...array_keys(self::SINGLE), ...array_keys(self::MULTI)];
    }

    public static function tagFields(): array
    {
        return [...array_keys(self::SINGLE), ...array_keys(self::MULTI)];
    }

    /** Màu dạng chữ thường không dấu, liền bằng "_": "Đỏ đô" -> "do_do". */
    public static function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::ascii(str_replace(['đ', 'Đ'], 'd', mb_strtolower(trim($value))))), '_');
    }

    /**
     * Kiểm tra + chuẩn hoá tag. Chỉ trường có trong $tags; giá trị null / rỗng = xoá trường (trả null).
     * $required: trường bắt buộc phải có giá trị.
     *
     * @return array{0: array<string, mixed>, 1: string[]} [tag sạch, lỗi]
     */
    public static function clean(array $tags, array $required = []): array
    {
        $out = [];
        $errors = [];
        foreach ($tags as $field => $value) {
            if (isset(self::SINGLE[$field])) {
                $v = is_string($value) ? self::slug($value) : null;
                if ($v === null || $v === '') {
                    $out[$field] = null;
                } elseif (! in_array($v, self::SINGLE[$field], true)) {
                    $errors[] = "{$field} \"{$value}\" không hợp lệ (".implode(', ', self::SINGLE[$field]).').';
                } else {
                    $out[$field] = $v;
                }
            } elseif (isset(self::MULTI[$field])) {
                [$allowed, $max] = self::MULTI[$field];
                $list = collect(is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]))
                    ->map(fn ($v) => self::slug((string) $v))->filter()->unique()->values();
                $bad = $allowed ? $list->reject(fn ($v) => in_array($v, $allowed, true)) : $list->filter(fn ($v) => strlen($v) > self::MAU_MAX);
                if ($bad->isNotEmpty()) {
                    $errors[] = "{$field} không hợp lệ: ".$bad->implode(', ').($allowed ? ' ('.implode(', ', $allowed).').' : ' (tối đa '.self::MAU_MAX.' ký tự).');
                } elseif ($list->count() > $max) {
                    $errors[] = "{$field} tối đa {$max} giá trị.";
                } else {
                    $out[$field] = $list->isEmpty() ? null : $list->all();
                }
            } else {
                $errors[] = "Không có trường tag \"{$field}\".";
            }
        }
        foreach ($required as $field) {
            if (empty($out[$field]) && ! collect($errors)->contains(fn ($e) => str_starts_with($e, $field))) {
                $errors[] = "{$field} bắt buộc.";
            }
        }

        return [$out, $errors];
    }

    /**
     * Bộ lọc tag (chủ đề kênh, API lọc sản phẩm): mọi trường là danh sách giá trị, trường rỗng bị bỏ.
     *
     * @return array{0: array<string, string[]>, 1: string[]}
     */
    public static function cleanFilter(array $filter): array
    {
        $out = [];
        $errors = [];
        foreach ($filter as $field => $value) {
            $allowed = self::SINGLE[$field] ?? (isset(self::MULTI[$field]) ? self::MULTI[$field][0] : false);
            if ($allowed === false) {
                $errors[] = "Không có trường tag \"{$field}\".";

                continue;
            }
            $list = collect(is_array($value) ? $value : explode(',', (string) $value))
                ->map(fn ($v) => self::slug((string) $v))->filter()->unique()->values();
            $bad = $allowed ? $list->reject(fn ($v) => in_array($v, $allowed, true)) : collect();
            if ($bad->isNotEmpty()) {
                $errors[] = "{$field} không hợp lệ: ".$bad->implode(', ').' ('.implode(', ', $allowed).').';
            } elseif ($list->isNotEmpty()) {
                $out[$field] = $list->all();
            }
        }

        return [$out, $errors];
    }

    public static function cleanLabel(?string $label): ?string
    {
        $label = trim(preg_replace(['/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '/\s+/u'], ['', ' '], (string) $label));

        return $label === '' ? null : $label;
    }

    /**
     * Ghi các trường vào sản phẩm (chưa save). $source = ai: bỏ qua trường chủ shop đã sửa (trả về tên các trường bỏ
     * qua). $source = owner: trường thành nguồn owner; xoá giá trị -> bỏ nguồn (AI gắn lại được).
     *
     * @param  array<string, mixed>  $values  ai_label + tag đã clean
     * @return string[] trường bị bỏ qua
     */
    public static function apply(Product $product, array $values, string $source): array
    {
        $tags = $product->tags ?? [];
        $sources = $product->tag_sources ?? [];
        $skipped = [];
        foreach ($values as $field => $value) {
            if ($source === self::SOURCE_AI && ($sources[$field] ?? null) === self::SOURCE_OWNER) {
                $skipped[] = $field;

                continue;
            }
            if ($field === 'ai_label') {
                $product->ai_label = $value;
            } elseif ($value === null) {
                unset($tags[$field]);
            } else {
                $tags[$field] = $value;
            }
            if ($value === null && $source === self::SOURCE_OWNER) {
                unset($sources[$field]);
            } else {
                $sources[$field] = $source;
            }
        }
        $product->tags = $tags ?: null;
        $product->tag_sources = $sources ?: null;

        return $skipped;
    }

    /**
     * Sản phẩm khớp bộ lọc: mỗi trường lọc là danh sách giá trị (HOẶC trong trường), giữa các trường VÀ.
     *
     * @param  array<string, string[]>  $filter
     */
    public static function matches(?array $tags, array $filter): bool
    {
        foreach ($filter as $field => $wanted) {
            if (! $wanted) {
                continue;
            }
            $have = (array) ($tags[$field] ?? []);
            if (! array_intersect($have, $wanted)) {
                return false;
            }
        }

        return true;
    }
}
