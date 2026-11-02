<?php

namespace App\Enums;

/**
 * Nền tảng đăng bài. Mỗi nền tảng có 1 cột trạng thái trong social_posts ({value}_status) và giới hạn riêng, kiểm tra
 * lúc bấm đăng. Worker được giao bài tự đăng lên mọi nền tảng đã chọn (note/gop-luong-dang-bai.txt ở repo frontend).
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

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
