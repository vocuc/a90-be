<?php

namespace App\Models;

use App\Services\Social\SocialContentJobService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cài đặt AI viết nội dung của shop (owner_id = chủ shop; nhân viên dùng của chủ shop): số ký tự tối đa của tiêu đề /
 * nội dung AI viết, bài viết mẫu [{ title, content }]. Shop chưa lưu lần nào: dùng defaults().
 */
class ShopAiSetting extends Model
{
    public const MAX_SAMPLES = 10;

    // Giới hạn nhỏ nhất người dùng được đặt; lớn nhất: SocialPost::TITLE_MAX, SocialContentJobService::AI_CONTENT_MAX
    public const TITLE_MAX_MIN = 10;

    public const CONTENT_MAX_MIN = 50;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['samples' => 'array'];
    }

    /** @return array{title_max: int, content_max: int, samples: array<int, array{title: string, content: string}>} */
    public static function defaults(): array
    {
        return [
            'title_max' => SocialPost::TITLE_MAX,
            'content_max' => SocialContentJobService::AI_CONTENT_MAX,
            'samples' => [],
        ];
    }

    /**
     * Cài đặt của chủ shop, chưa lưu thì mặc định.
     *
     * @return array{title_max: int, content_max: int, samples: array<int, array{title: string, content: string}>}
     */
    public static function forOwner(int $ownerId): array
    {
        $setting = static::query()->where('owner_id', $ownerId)->first();

        return $setting
            ? ['title_max' => $setting->title_max, 'content_max' => $setting->content_max, 'samples' => $setting->samples ?? []]
            : static::defaults();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
