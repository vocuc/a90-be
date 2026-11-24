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

    /**
     * Hashtag LOẠI GIÀY dùng chung A51 + OOTD (người dùng chốt 2026-11-24): tag kieu của sản phẩm = 1 hashtag dưới đây; skill
     * /tao-outfit đọc danh sách qua GET /api/v1/shoe-types để gắn hashtag giày cho outfit OOTD; worker phối đồ ghép outfit
     * cùng hashtag trước, cùng nhóm sau. Chỉ quy định loại giày, KHÔNG quy định màu. Hashtag viết liền không dấu như
     * Hashtag::normalize của OOTD. tag => [tên, nhóm, cùng nghĩa (hashtag cũ / kieu cũ: đọc được nhưng không gắn mới)].
     */
    public const SHOE_TYPES = [
        'giaycaogot' => ['Cao gót', 'giaycaogot', ['caogot']],
        'giayslingback' => ['Slingback', 'giaycaogot', ['slingback']],
        'giaymule' => ['Mule', 'giaymule', ['mule']],
        'giaysandal' => ['Sandal', 'giaysandal', ['sandal']],
        'giaybet' => ['Giày bệt', 'giaybet', []],
        'giaybupbe' => ['Búp bê', 'giaybet', ['bupbe']],
        'giaymaryjane' => ['Mary Jane', 'giaybet', ['maryjane']],
        'giayloafer' => ['Loafer', 'giaybet', ['loafer']],
        'giaytabi' => ['Tabi', 'giaybet', ['tabi']],
        'giaythethao' => ['Sneaker / thể thao', 'giaythethao', ['sneaker', 'giaysneaker', 'giaydeutrainer']],
        'giayboot' => ['Boot', 'giayboot', ['boot']],
        'bootcaoco' => ['Boot cao cổ', 'giayboot', []],
        'bootlong' => ['Boot cổ dài', 'giayboot', []],
        'bootdui' => ['Boot đùi', 'giayboot', []],
        'bootdituyet' => ['Boot đi tuyết', 'giayboot', ['boottuyet']],
        'bootcongan' => ['Boot cổ ngắn', 'giayboot', []],
        'bootcaosu' => ['Boot cao su', 'giayboot', []],
        'bootcaoboi' => ['Boot cao bồi', 'giayboot', []],
        'bootcombat' => ['Boot combat', 'giayboot', []],
        'bootbiker' => ['Boot biker', 'giayboot', []],
        'giayclog' => ['Clog', 'giayclog', ['clog']],
        'giaysuc' => ['Giày sục', 'giayclog', []],
        'guocgo' => ['Guốc gỗ', 'guocgo', ['guoc']],
        'giaydep' => ['Dép', 'giaydep', ['dep']],
        'depquai' => ['Dép quai', 'giaydep', []],
        'depkep' => ['Dép kẹp', 'giaydep', []],
        'deplong' => ['Dép lông', 'giaydep', []],
    ];

    /** Trường 1 giá trị (enum). kieu = đúng các khoá của SHOE_TYPES (test kiểm tra). */
    public const SINGLE = [
        'kieu' => [
            'giaycaogot', 'giayslingback', 'giaymule', 'giaysandal', 'giaybet', 'giaybupbe', 'giaymaryjane', 'giayloafer',
            'giaytabi', 'giaythethao', 'giayboot', 'bootcaoco', 'bootlong', 'bootdui', 'bootdituyet', 'bootcongan', 'bootcaosu',
            'bootcaoboi', 'bootcombat', 'bootbiker', 'giayclog', 'giaysuc', 'guocgo', 'giaydep', 'depquai', 'depkep', 'deplong',
        ],
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

    /** Như Hashtag::normalize của OOTD: "Giày cao gót" / "#giay_cao-got" -> "giaycaogot". */
    public static function ootdTag(string $tag): string
    {
        return str_replace('_', '', self::slug($tag));
    }

    /** Hashtag bất kỳ ("Giày cao gót", "#sneaker", kieu cũ "cao_got") -> hashtag loại giày trong SHOE_TYPES | null. */
    public static function shoeType(string $value): ?string
    {
        $tag = self::ootdTag($value);
        if (isset(self::SHOE_TYPES[$tag])) {
            return $tag;
        }
        foreach (self::SHOE_TYPES as $key => [, , $same]) {
            if (in_array($tag, $same, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Danh sách loại giày cho API GET /api/v1/shoe-types.
     *
     * @return array<int, array{tag: string, ten: string, nhom: string, cung_nghia: string[]}>
     */
    public static function shoeTypes(): array
    {
        return collect(self::SHOE_TYPES)
            ->map(fn ($t, $tag) => ['tag' => $tag, 'ten' => $t[0], 'nhom' => $t[1], 'cung_nghia' => $t[2]])->values()->all();
    }

    /**
     * Loại giày của sản phẩm cho worker phối đồ ghép outfit OOTD: cung_tag = hashtag + cùng nghĩa (ghép trước), cung_nhom =
     * mọi hashtag (kể cả cùng nghĩa) cùng nhóm (ghép sau). null = không phải loại giày.
     */
    public static function shoeMatch(?string $tag): ?array
    {
        if (! $tag || ! isset(self::SHOE_TYPES[$tag])) {
            return null;
        }
        [$name, $group, $same] = self::SHOE_TYPES[$tag];
        $groupTags = collect(self::SHOE_TYPES)->filter(fn ($t) => $t[1] === $group)
            ->flatMap(fn ($t, $k) => [$k, ...$t[2]])->values()->all();

        return ['tag' => $tag, 'ten' => $name, 'nhom' => $group, 'cung_tag' => [$tag, ...$same], 'cung_nhom' => $groupTags];
    }

    /** Giá trị trường 1 giá trị đã chuẩn hoá: kieu -> hashtag loại giày (chữ lạ giữ nguyên để báo lỗi), got -> slug. */
    private static function single(string $field, string $value): string
    {
        return $field === 'kieu' ? (self::shoeType($value) ?? self::ootdTag($value)) : self::slug($value);
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
                $v = is_string($value) ? self::single($field, $value) : null;
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
                ->map(fn ($v) => isset(self::SINGLE[$field]) ? self::single($field, (string) $v) : self::slug((string) $v))
                ->filter()->unique()->values();
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
