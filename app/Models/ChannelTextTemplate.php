<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template chữ chèn lên ảnh của kênh (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.4). Lệnh /lap-ke-hoach
 * tạo 6 mẫu (pending, hoặc approved nếu kênh bật tự duyệt), chủ shop duyệt / từ chối / sửa. Worker đăng bài chèn chữ
 * theo style.
 */
class ChannelTextTemplate extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Font cố định có tiếng Việt, worker cài sẵn. */
    public const FONTS = ['Be Vietnam Pro', 'Montserrat', 'Roboto', 'Lexend', 'Nunito'];

    public const POSITIONS = ['top', 'middle', 'bottom'];

    public const BACKGROUNDS = ['none', 'box'];

    public const ALIGNS = ['center', 'left'];

    /** Số mẫu mỗi lần lệnh tạo. */
    public const SUGGEST_COUNT = 6;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['style' => 'array'];
    }

    /** Luật kiểm tra style (dùng chung cho API skill và API chủ shop). */
    public static function styleRules(string $prefix): array
    {
        $color = 'regex:/^#[0-9A-Fa-f]{6}$/';

        return [
            $prefix => ['required', 'array'],
            "{$prefix}.position" => ['required', 'in:'.implode(',', self::POSITIONS)],
            "{$prefix}.font" => ['required', 'in:'.implode(',', self::FONTS)],
            "{$prefix}.weight" => ['required', 'integer', 'min:400', 'max:900'],
            "{$prefix}.size" => ['required', 'numeric', 'min:3', 'max:10'],
            "{$prefix}.color" => ['required', 'string', $color],
            "{$prefix}.stroke" => ['nullable', 'array'],
            "{$prefix}.stroke.color" => ["required_with:{$prefix}.stroke", 'string', $color],
            "{$prefix}.stroke.width_px" => ["required_with:{$prefix}.stroke", 'integer', 'min:0', 'max:20'],
            "{$prefix}.background" => ['required', 'in:'.implode(',', self::BACKGROUNDS)],
            "{$prefix}.box_color" => ['nullable', 'string', $color],
            "{$prefix}.box_radius" => ['nullable', 'integer', 'min:0', 'max:60'],
            "{$prefix}.align" => ['required', 'in:'.implode(',', self::ALIGNS)],
            "{$prefix}.max_lines" => ['required', 'integer', 'min:1', 'max:6'],
        ];
    }

    /** Chỉ giữ các khoá đã định nghĩa của style, theo thứ tự cố định. */
    public static function cleanStyle(array $style): array
    {
        $stroke = $style['stroke'] ?? null;

        return [
            'position' => $style['position'],
            'font' => $style['font'],
            'weight' => (int) $style['weight'],
            'size' => (float) $style['size'],
            'color' => strtoupper($style['color']),
            'stroke' => $stroke ? ['color' => strtoupper($stroke['color']), 'width_px' => (int) $stroke['width_px']] : null,
            'background' => $style['background'],
            'box_color' => isset($style['box_color']) ? strtoupper($style['box_color']) : null,
            'box_radius' => (int) ($style['box_radius'] ?? 0),
            'align' => $style['align'],
            'max_lines' => (int) $style['max_lines'],
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
