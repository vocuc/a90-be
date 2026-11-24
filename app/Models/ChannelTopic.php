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
     * Nhóm chủ đề tạo sẵn khi kênh chưa có nhóm nào (người dùng chốt 2026-11-24). Nhóm do chủ shop quản lý (bảng
     * channel_topic_categories); chủ đề không thuộc nhóm nào hiện ở "Khác".
     */
    public const DEFAULT_CATEGORIES = ['Sự kiện', 'Theo mùa', 'Đi làm', 'Du lịch', 'Hằng ngày'];

    public const OTHER_CATEGORY = 'Khác';

    /** Từ khoá đoán nhóm (tên nhóm mặc định) khi chủ đề tạo mà không chọn nhóm; thứ tự = ưu tiên khi khớp nhiều nhóm. */
    private const CATEGORY_WORDS = [
        'Sự kiện' => ['tết', 'tiệc', 'valentine', 'noel', 'giáng sinh', 'trung thu', '8/3', '20/10', '20/11', '14/2', 'cưới', 'sinh nhật',
            'lễ', 'mùng', 'du xuân', 'chơi xuân', 'kỷ niệm', 'black friday', 'sale', 'cuối năm', 'tất niên', 'halloween', 'tốt nghiệp'],
        'Đi làm' => ['đi làm', 'công sở', 'văn phòng', 'phỏng vấn', 'họp', 'đi học'],
        'Du lịch' => ['du lịch', 'đà lạt', 'biển', 'phượt', 'nghỉ dưỡng', 'sapa', 'hội an', 'phú quốc', 'vali', 'chuyến đi'],
        // Sau Du lịch ("Đi biển mùa hè" -> Du lịch), trước Hằng ngày
        'Theo mùa' => ['mùa', 'trời lạnh', 'se lạnh', 'rét', 'nắng nóng', 'trời nóng', 'trời mưa', 'ngày mưa', 'thu đông', 'xuân hè'],
        'Hằng ngày' => ['hằng ngày', 'hàng ngày', 'đi chơi', 'cafe', 'cà phê', 'dạo phố', 'hẹn hò', 'cuối tuần', 'mua sắm', 'ở nhà'],
    ];

    /** Tên nhóm mặc định đoán theo tên / ghi chú chủ đề, null = không đoán được ("Khác"). */
    public static function guessCategory(?string $text): ?string
    {
        $text = mb_strtolower((string) $text);
        foreach (self::CATEGORY_WORDS as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $category;
                }
            }
        }

        return null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChannelTopicCategory::class, 'category_id');
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
