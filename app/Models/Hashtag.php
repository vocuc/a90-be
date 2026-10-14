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

    public function creatives(): BelongsToMany
    {
        return $this->belongsToMany(AiCreative::class, 'ai_creative_hashtag', 'hashtag_id', 'creative_id');
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
