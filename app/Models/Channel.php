<?php

namespace App\Models;

use App\Services\Social\SocialContentJobService;
use App\Support\PostSamples;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kênh của shop (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt): 1 tài khoản TikTok, đăng qua đúng 1 worker publish
 * (worker_id unique). Có chân dung khách hàng (persona), cấu hình kế hoạch (plan_days, posts_per_day, time_slots,
 * video_percent, công tắc tự duyệt) và cài đặt AI viết nội dung (title_max, content_max, requirements, kho hashtag).
 * Mọi bài đăng thuộc 1 kênh: bài nhận channel_id theo worker được giao (SocialPost::booted).
 */
class Channel extends Model
{
    public const MAX_PER_SHOP = 10;

    public const PLATFORM_TIKTOK = 'tiktok';

    public const NAME_MAX = 100;

    public const PLAN_DAYS_MAX = 30;

    public const POSTS_PER_DAY_MAX = 20;

    public const TIME_SLOTS_MAX = 6;

    public const REQUIREMENTS_MAX = 3000;

    public const TITLE_MAX_MIN = 10;

    public const CONTENT_MAX_MIN = 50;

    public const MAX_HASHTAGS = 100;

    public const PERSONA_FIELD_MAX = 300;

    /** Các trường chân dung khách hàng (đều không bắt buộc). */
    public const PERSONA_FIELDS = [
        'age_range', 'gender', 'occupation', 'price_range', 'style', 'pain_points', 'occasions', 'tone', 'active_hours', 'note',
    ];

    protected $guarded = ['id'];

    protected $attributes = [
        'platform' => self::PLATFORM_TIKTOK,
    ];

    protected function casts(): array
    {
        return [
            'persona' => 'array',
            'time_slots' => 'array',
            'auto_approve_lineup' => 'boolean',
            'auto_approve_templates' => 'boolean',
            'auto_approve_plan' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Cài đặt AI mặc định cho kênh mới (shop chưa từng lưu cài đặt AI). */
    public static function aiDefaults(): array
    {
        return [
            'title_max' => SocialPost::TITLE_MAX,
            'content_max' => SocialContentJobService::AI_CONTENT_MAX,
            'requirements' => null,
        ];
    }

    /**
     * Cài đặt AI viết nội dung của kênh, dạng SocialContentJobService dùng.
     *
     * @return array{title_max: int, content_max: int, requirements: string, hashtags: string[]}
     */
    public function aiSettings(): array
    {
        return [
            'title_max' => (int) $this->title_max,
            'content_max' => (int) $this->content_max,
            'requirements' => (string) $this->requirements,
            'hashtags' => $this->hashtags()->pluck('name')->all(),
        ];
    }

    /** Chân dung: chỉ giữ các trường đã định nghĩa (theo thứ tự cố định), cắt khoảng trắng, bỏ trường rỗng. */
    public static function normalizePersona(array $persona): ?array
    {
        $result = [];
        foreach (self::PERSONA_FIELDS as $field) {
            $value = trim((string) ($persona[$field] ?? ''));
            if ($value !== '') {
                $result[$field] = mb_substr($value, 0, self::PERSONA_FIELD_MAX);
            }
        }

        return $result ?: null;
    }

    /** Những gì kênh còn thiếu để lập kế hoạch (hiển thị cho chủ shop, lệnh /lap-ke-hoach đọc). */
    /**
     * % giờ đăng VIDEO của kỳ = tổng % các bài viết mẫu CHỈ làm video (vd video_type_01) trong tuyến đã duyệt (chỉ bài đang
     * bật). Thay ô "% bài video" ở Cấu hình (người dùng bỏ 2026-11-22; cột video_percent không dùng nữa). Chưa có tuyến: 0.
     */
    public function videoPercent(): int
    {
        $items = array_filter($this->approvedLineup()?->items ?? [], fn ($i) => PostSamples::isEnabled($i['sample_key']));
        $total = array_sum(array_map(fn ($i) => (float) $i['percent'], $items));
        if ($total <= 0) {
            return 0;
        }
        $video = array_sum(array_map(fn ($i) => (PostSamples::get($i['sample_key'])['formats'] ?? []) === ['video'] ? (float) $i['percent'] : 0, $items));

        return (int) round($video * 100 / $total);
    }

    public function missing(): array
    {
        $missing = [];
        if (! $this->worker_id) {
            $missing[] = 'worker';
        }
        if (! array_filter($this->persona ?? [], fn ($value) => trim((string) $value) !== '')) {
            $missing[] = 'persona';
        }
        if (! $this->time_slots) {
            $missing[] = 'time_slots';
        }
        // Tuyến đã duyệt phải có ít nhất 1 bài viết mẫu ĐÃ LÀM XONG (PostSamples::READY)
        if (! array_filter($this->approvedLineup()?->sampleKeys() ?? [], [PostSamples::class, 'isEnabled'])) {
            $missing[] = 'lineup';
        }
        // Tuyến có bài ảnh có chữ thì cần ít nhất 1 template chữ đã duyệt CÓ GẮN loại bài (template chưa gắn
        // loại bài nào không dùng được)
        // (Tuyến chỉ có bài không chữ, vd unboxing, thì không cần template)
        $needsText = ! $this->approvedLineup() || array_intersect($this->approvedLineup()->sampleKeys(), ChannelTextTemplate::sampleKeyOptions());
        if ($needsText && ! $this->textTemplates()->where('status', ChannelTextTemplate::APPROVED)->get()
            ->contains(fn (ChannelTextTemplate $t) => ! empty($t->sample_keys))) {
            $missing[] = 'text_templates';
        }

        return $missing;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'channel_hashtag')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function lineups(): HasMany
    {
        return $this->hasMany(ChannelLineup::class);
    }

    public function textTemplates(): HasMany
    {
        return $this->hasMany(ChannelTextTemplate::class);
    }

    /** Chủ đề của kênh (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt mục 4). */
    public function topics(): HasMany
    {
        return $this->hasMany(ChannelTopic::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ContentPlan::class);
    }

    /** Tuyến nội dung đang dùng: bản approved mới nhất. */
    public function approvedLineup(): ?ChannelLineup
    {
        return $this->lineups()->where('status', ChannelLineup::APPROVED)->latest('approved_at')->latest('id')->first();
    }
}
