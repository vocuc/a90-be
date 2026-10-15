<?php

namespace App\Models;

use App\Enums\TikTokPostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class TikTokPost extends Model
{
    /** Tạo từ nút "Đăng TikTok" của lô ảnh. */
    public const SOURCE_BATCH = 'batch';

    public const SOURCE_EXISTING = 'existing';

    // Giới hạn TikTok photo post
    public const MAX_IMAGES = 35;

    public const TITLE_MAX = 90;

    public const CONTENT_MAX = 4000;

    public const MAX_HASHTAGS = 10;

    public const DEFAULT_TITLE = 'Ảnh mới từ A51';

    protected $table = 'tiktok_posts';

    protected $guarded = ['id'];

    protected $attributes = [
        'title' => '',
    ];

    protected function casts(): array
    {
        return [
            'status' => TikTokPostStatus::class,
            'hashtags' => 'array',
            'last_error' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Hashtag theo quy tắc của Creative: bỏ "#", chỉ chữ / số / "_", bỏ trùng (so theo slug), tối đa 10.
     *
     * @param  array<int, mixed>  $tags
     * @return string[]
     */
    public static function normalizeHashtags(array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $name = Hashtag::cleanName($tag);
            $slug = Hashtag::slugFor($name);
            if ($slug === '' || mb_strlen($name) > 50 || ! preg_match(Hashtag::PATTERN, $name) || isset($result[$slug])) {
                continue;
            }
            $result[$slug] = $name;
        }

        return array_slice(array_values($result), 0, self::MAX_HASHTAGS);
    }

    /** Mô tả điền vào TikTok: nội dung + dòng hashtag. */
    public static function description(?string $content, array $hashtags): string
    {
        $tags = implode(' ', array_map(fn ($tag) => '#'.$tag, $hashtags));

        return trim(trim((string) $content)."\n".$tags);
    }

    /** Người dùng đã tự nhập tiêu đề / nội dung / hashtag khi tạo bài. */
    public function hasManualContent(): bool
    {
        return $this->title !== '' || trim((string) $this->content) !== '' || ! empty($this->hashtags);
    }

    /**
     * Nội dung mặc định từ các mẫu của bài: tên mẫu đầu tiên + hashtag các mẫu. Người dùng sửa ở bước duyệt.
     *
     * @return array{title: string, content: string, hashtags: string[]}
     */
    public function defaultContent(): array
    {
        $creatives = Generation::query()
            ->whereIn('id', $this->images()->pluck('generation_id'))
            ->with('creative.hashtags')
            ->get()
            ->pluck('creative')
            ->filter()
            ->unique('id')
            ->values();

        return [
            'title' => $creatives->isNotEmpty() ? Str::limit($creatives->first()->title, self::TITLE_MAX, '') : self::DEFAULT_TITLE,
            'content' => '',
            'hashtags' => self::normalizeHashtags($creatives->flatMap(fn ($c) => $c->hashtags->pluck('name'))->all()),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ảnh theo thứ tự, ảnh đầu là ảnh bìa. */
    public function images(): HasMany
    {
        return $this->hasMany(TikTokPostImage::class, 'tiktok_post_id')->orderBy('position')->orderBy('id');
    }

    public function contentJob(): HasOne
    {
        return $this->hasOne(TikTokContentJob::class, 'tiktok_post_id');
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(TikTokPublishJob::class, 'tiktok_post_id');
    }

    public function imageBatch(): BelongsTo
    {
        return $this->belongsTo(ImageBatch::class, 'image_batch_id');
    }

    /** Tài khoản TikTok của worker đã nhận job đăng gần nhất. */
    public function tiktokAccount(): BelongsTo
    {
        return $this->belongsTo(TikTokAccount::class, 'tiktok_account_id');
    }
}
