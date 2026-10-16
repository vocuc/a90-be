<?php

namespace App\Enums;

/**
 * Nền tảng đăng bài. Mỗi nền tảng có 1 cột trạng thái trong social_posts ({value}_status),
 * worker riêng (PublishAccount.platform) và giới hạn riêng, kiểm tra lúc bấm đăng.
 */
enum SocialPlatform: string
{
    case Tiktok = 'tiktok';
    case Facebook = 'facebook';
    case Instagram = 'instagram';

    public function label(): string
    {
        return match ($this) {
            self::Tiktok => 'TikTok',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
        };
    }

    /** Cột trạng thái của nền tảng trong social_posts. */
    public function statusColumn(): string
    {
        return $this->value.'_status';
    }

    /** Số ảnh tối đa mỗi bài. */
    public function maxImages(): int
    {
        return match ($this) {
            self::Tiktok, self::Facebook => 35,
            self::Instagram => 10, // carousel
        };
    }

    /** Độ dài tối đa của mô tả (nội dung + dòng hashtag). */
    public function descriptionMax(): int
    {
        return match ($this) {
            self::Tiktok => 4000,
            self::Facebook => 63206,
            self::Instagram => 2200,
        };
    }

    /** Mã lỗi khi worker chưa được gắn tài khoản đang bật của nền tảng, vd TIKTOK_ACCOUNT_NOT_ASSIGNED. */
    public function notAssignedCode(): string
    {
        return strtoupper($this->value).'_ACCOUNT_NOT_ASSIGNED';
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
