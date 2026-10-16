<?php

namespace App\Models;

use App\Enums\PlatformPublishStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Bài đăng: nội dung chung (tiêu đề, nội dung, hashtag, ảnh) đăng lên một hoặc nhiều nền tảng.
 * Trạng thái từng nền tảng: tiktok_status / facebook_status / instagram_status (null: không đăng lên nền tảng đó);
 * status là trạng thái chung, tính lại bằng syncStatus() mỗi khi trạng thái một nền tảng đổi.
 */
class SocialPost extends Model
{
    /** Tạo từ nút "Tạo bài" của lô ảnh. */
    public const SOURCE_BATCH = 'batch';

    public const SOURCE_EXISTING = 'existing';

    // Giới hạn chung lúc tạo / sửa bài (lớn nhất trong các nền tảng); giới hạn riêng: SocialPlatform, kiểm tra lúc bấm đăng
    public const MAX_IMAGES = 35;

    public const TITLE_MAX = 90;

    public const CONTENT_MAX = 4000;

    public const MAX_HASHTAGS = 10;

    public const DEFAULT_TITLE = 'Ảnh mới từ A51';

    /** Mã lỗi chung người dùng thấy khi có nền tảng đăng lỗi (last_error.code). */
    public const PUBLISH_FAILED = 'PUBLISH_FAILED';

    protected $table = 'social_posts';

    protected $guarded = ['id'];

    protected $attributes = [
        'title' => '',
    ];

    protected function casts(): array
    {
        return [
            'status' => SocialPostStatus::class,
            'tiktok_status' => PlatformPublishStatus::class,
            'facebook_status' => PlatformPublishStatus::class,
            'instagram_status' => PlatformPublishStatus::class,
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

    /** Mô tả điền vào ô nội dung của nền tảng: nội dung + dòng hashtag. */
    public static function description(?string $content, array $hashtags): string
    {
        $tags = implode(' ', array_map(fn ($tag) => '#'.$tag, $hashtags));

        return trim(trim((string) $content)."\n".$tags);
    }

    public function platformStatus(SocialPlatform $platform): ?PlatformPublishStatus
    {
        return $this->{$platform->statusColumn()};
    }

    /**
     * Các nền tảng bài đã chọn đăng (cột trạng thái khác null).
     *
     * @return array<string, PlatformPublishStatus> platform => trạng thái
     */
    public function platformStatuses(): array
    {
        $result = [];
        foreach (SocialPlatform::cases() as $platform) {
            if ($status = $this->platformStatus($platform)) {
                $result[$platform->value] = $status;
            }
        }

        return $result;
    }

    /** Đổi trạng thái 1 nền tảng, tính lại trạng thái chung rồi lưu. Gọi trong transaction đã khoá dòng bài. */
    public function setPlatformStatus(SocialPlatform $platform, PlatformPublishStatus $status): void
    {
        $this->{$platform->statusColumn()} = $status;
        $this->syncStatus();
        $this->save();
    }

    /**
     * Trạng thái chung từ các nền tảng đã chọn: còn nền tảng đang đăng -> publishing, còn chờ -> queued,
     * xong hết -> published, xong hết nhưng có nền tảng lỗi -> pending_review (khoá mở, đăng lại các nền tảng lỗi).
     * Mã lỗi chi tiết chỉ lưu ở job cho admin; người dùng thấy thông báo chung kèm tên nền tảng lỗi.
     */
    public function syncStatus(): void
    {
        $statuses = collect($this->platformStatuses());
        if ($statuses->isEmpty()) {
            return;
        }

        if ($statuses->contains(PlatformPublishStatus::Publishing)) {
            $this->status = SocialPostStatus::Publishing;
        } elseif ($statuses->contains(PlatformPublishStatus::Queued)) {
            $this->status = SocialPostStatus::Queued;
        } elseif ($statuses->every(fn ($status) => $status === PlatformPublishStatus::Published)) {
            $this->status = SocialPostStatus::Published;
            $this->published_at ??= now();
            $this->last_error = null;
        } else {
            $failed = $statuses->filter(fn ($status) => $status === PlatformPublishStatus::Failed)->keys();
            $labels = $failed->map(fn ($platform) => SocialPlatform::from($platform)->label())->implode(', ');
            $this->status = SocialPostStatus::PendingReview;
            $this->last_error = [
                'code' => self::PUBLISH_FAILED,
                'message' => "Đăng lên {$labels} thất bại, vui lòng thử lại.",
                'platforms' => $failed->values()->all(),
            ];
        }
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
        return $this->hasMany(SocialPostImage::class, 'social_post_id')->orderBy('position')->orderBy('id');
    }

    public function contentJob(): HasOne
    {
        return $this->hasOne(SocialContentJob::class, 'social_post_id');
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(SocialPublishJob::class, 'social_post_id');
    }

    public function imageBatch(): BelongsTo
    {
        return $this->belongsTo(ImageBatch::class, 'image_batch_id');
    }
}
