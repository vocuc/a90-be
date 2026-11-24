<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chủ đề CỤ THỂ của kênh ("Đi chúc Tết", "Đi làm cả ngày không đau chân"), dùng chung mọi loại bài: tên + ghi chú (không
 * bắt buộc; từ 2026-11-22 thay bộ lọc tag). Lệnh lập kế hoạch đọc ghi chú rồi tự chọn tag lọc sản phẩm + viết hook; không
 * có ghi chú thì sáng tạo tự do theo tên. Worker lập chủ đề / chủ shop tạo, chủ shop sửa / tạm tắt được. Bài đăng ghi
 * topic_id để sau thống kê. a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt mục 4.
 */
class ChannelTopic extends Model
{
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const NAME_MAX = 80;

    public const NOTE_MAX = 1000;

    /**
     * Nhóm chủ đề (người dùng chốt 2026-11-24): tab Chủ đề hiện chủ đề theo nhóm; chủ đề vẫn cụ thể (hook viết theo chủ đề).
     * Thứ tự = thứ tự hiện trên tab.
     */
    public const CATEGORIES = [
        'su_kien' => 'Sự kiện',
        'di_lam' => 'Đi làm',
        'du_lich' => 'Du lịch',
        'hang_ngay' => 'Hằng ngày',
        'khac' => 'Khác',
    ];

    public const DEFAULT_CATEGORY = 'khac';

    /** Từ khoá đoán nhóm cho chủ đề cũ (migration) / worker không gửi nhóm. */
    private const CATEGORY_WORDS = [
        'su_kien' => ['tết', 'tiệc', 'valentine', 'noel', 'giáng sinh', 'trung thu', '8/3', '20/10', '20/11', '14/2', 'cưới', 'sinh nhật',
            'lễ', 'mùng', 'du xuân', 'chơi xuân', 'kỷ niệm', 'black friday', 'sale', 'cuối năm', 'tất niên', 'year end', 'halloween', 'tốt nghiệp', 'khai giảng', 'hội'],
        'di_lam' => ['đi làm', 'công sở', 'văn phòng', 'phỏng vấn', 'họp', 'đi học'],
        'du_lich' => ['du lịch', 'đà lạt', 'biển', 'phượt', 'nghỉ dưỡng', 'sapa', 'hội an', 'phú quốc', 'vali', 'chuyến đi'],
        'hang_ngay' => ['hằng ngày', 'hàng ngày', 'đi chơi', 'cafe', 'cà phê', 'dạo phố', 'hẹn hò', 'cuối tuần', 'mua sắm', 'ở nhà'],
    ];

    public static function guessCategory(?string $text): string
    {
        $text = mb_strtolower((string) $text);
        foreach (self::CATEGORY_WORDS as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $category;
                }
            }
        }

        return self::DEFAULT_CATEGORY;
    }

    /** Nhóm hợp lệ hoặc null. */
    public static function cleanCategory(mixed $category): ?string
    {
        return is_string($category) && isset(self::CATEGORIES[$category]) ? $category : null;
    }

    /** Cùng chủ đề + cùng loại bài không lặp trong số ngày này (trước / sau giờ đăng). */
    public const REPEAT_DAYS = 14;

    protected $guarded = ['id'];

    /** Ghi chú: bỏ khoảng trắng thừa đầu / cuối, rỗng -> null. */
    public static function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    public static function cleanName(?string $name): string
    {
        return trim(preg_replace(['/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '/\s+/u'], ['', ' '], (string) $name));
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class, 'topic_id');
    }
}
