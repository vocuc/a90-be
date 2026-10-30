<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Hashtag extends Model
{
    /** Chữ cái (kể cả tiếng Việt có dấu), chữ số và "_", có thể có # ở đầu. */
    public const PATTERN = '/^#?[\p{L}\p{N}_]+$/u';

    protected $guarded = ['id'];

    /**
     * Dữ liệu sync() cho bảng nối hashtag (Creative, sản phẩm): [hashtag_id => ['sort_order' => i]] theo thứ tự nhập.
     * Trùng nhau (khác hoa/thường, dấu) chỉ lấy lần đầu. Hashtag mới được tạo với tên hiển thị như người dùng nhập;
     * hashtag đã có giữ tên cũ.
     *
     * @param  string[]  $names
     * @return array<int, array{sort_order: int}>
     */
    public static function syncPivot(array $names): array
    {
        // [slug => tên hiển thị]
        $names = collect($names)
            ->map(fn (string $name) => static::cleanName($name))
            ->unique(fn (string $name) => static::slugFor($name))
            ->mapWithKeys(fn (string $name) => [static::slugFor($name) => $name]);

        if ($names->isEmpty()) {
            return [];
        }

        $now = now();
        // insertOrIgnore: an toàn khi 2 người cùng tạo một hashtag mới
        static::query()->insertOrIgnore($names->map(fn ($name, $slug) => [
            'name' => $name, 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now,
        ])->values()->all());

        $ids = static::query()->whereIn('slug', $names->keys())->pluck('id', 'slug');

        return $names->keys()->values()->mapWithKeys(fn ($slug, $i) => [$ids[$slug] => ['sort_order' => $i]])->all();
    }

    public function creatives(): BelongsToMany
    {
        return $this->belongsToMany(AiCreative::class, 'ai_creative_hashtag', 'hashtag_id', 'creative_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_hashtag');
    }

    /** "#ChânDung" → "ChânDung" */
    public static function cleanName(string $value): string
    {
        return ltrim(trim($value), '#');
    }

    /** "#ChânDung" → "chandung". Rỗng nếu không còn ký tự hợp lệ. */
    public static function slugFor(string $value): string
    {
        return Str::lower(preg_replace('/[^A-Za-z0-9_]/', '', Str::ascii(static::cleanName($value))));
    }
}
