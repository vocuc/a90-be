<?php

namespace App\Models;

use App\Services\CreativeTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template prompt của Creator: các block { title, content, pinned, options[] } ghép thành một prompt.
 * Creative chọn Template thì prompt cuối = block thường + prompt riêng của Creative + block ghim cuối (merge()).
 *
 * options: các biến thể nội dung của block. Mỗi lần tạo ảnh lấy ngẫu nhiên 1 option nối sau nội dung chính
 * (randomPick()). Bản lưu sẵn / hiển thị / kiểm tra độ dài dùng option dài nhất (longestPick()) = trường hợp xấu nhất.
 */
class PromptTemplate extends Model
{
    public const MAX_OPTIONS = 20;

    public const MAX_OPTION_LENGTH = 2000;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function configs(): HasMany
    {
        return $this->hasMany(AiCreativeConfig::class, 'prompt_template_id');
    }

    /** Chọn option dài nhất (mặc định): prompt lưu sẵn, xem trước, kiểm tra độ dài. */
    public static function longestPick(): \Closure
    {
        return fn (array $options) => collect($options)->sortByDesc(fn ($o) => mb_strlen($o))->first() ?? '';
    }

    /** Chọn ngẫu nhiên 1 option: dùng khi tạo ảnh. */
    public static function randomPick(): \Closure
    {
        return fn (array $options) => $options ? $options[array_rand($options)] : '';
    }

    /** Option khác rỗng của block. */
    public static function options(array $block): array
    {
        $options = is_array($block['options'] ?? null) ? $block['options'] : [];

        return array_values(array_filter(array_map(fn ($o) => trim((string) $o), $options), fn ($o) => $o !== ''));
    }

    /**
     * Ghép block: "Tiêu đề\nNội dung\nOption", cách nhau 1 dòng trống. Khớp frontend promptTemplate.js.
     *
     * @param  \Closure(string[]): string|null  $pick  cách chọn option (null = dài nhất)
     */
    public static function compose(array $blocks, ?\Closure $pick = null): string
    {
        $pick ??= self::longestPick();
        $parts = [];
        foreach ($blocks as $block) {
            $title = trim((string) ($block['title'] ?? ''));
            $options = self::options($block);
            $body = implode("\n", array_filter([
                trim((string) ($block['content'] ?? '')),
                $options ? trim($pick($options)) : '',
            ], fn ($p) => $p !== ''));
            $part = trim($title !== '' && $body !== '' ? "{$title}\n{$body}" : $title.$body);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Prompt cuối: các block thường + prompt riêng của Creative + block ghim cuối (pinned).
     * Khớp frontend mergeTemplatePrompt.
     */
    public static function merge(array $blocks, ?string $ownPrompt, ?\Closure $pick = null): string
    {
        $pinned = fn (array $b) => (bool) ($b['pinned'] ?? false);

        return CreativeTemplate::mergePrompt(
            self::compose(array_filter($blocks, fn ($b) => ! $pinned($b)), $pick),
            $ownPrompt,
            self::compose(array_filter($blocks, $pinned), $pick),
        );
    }

    /** Prompt cuối của Creative dùng Template $id (null / không tìm thấy = chỉ prompt riêng). */
    public static function mergeFor(?int $id, ?string $ownPrompt): string
    {
        $template = $id ? self::query()->find($id) : null;

        return $template ? self::merge($template->blocks ?? [], $ownPrompt) : CreativeTemplate::mergePrompt($ownPrompt);
    }

    /**
     * Lỗi đầu tiên của các option so với trường của Creative (option dùng biến chưa khai báo / biến ảnh), null = hợp lệ.
     * Prompt gộp chỉ chứa option dài nhất nên phải kiểm tra riêng từng option.
     */
    public static function optionsError(array $fields, array $blocks): ?string
    {
        foreach ($blocks as $block) {
            foreach (self::options($block) as $option) {
                if (CreativeTemplate::variables($option) && $message = CreativeTemplate::promptError($fields, $option)) {
                    return 'Option của block'.(trim((string) ($block['title'] ?? '')) !== '' ? ' "'.trim($block['title']).'"' : '').': '.$message;
                }
            }
        }

        return null;
    }

    public function hasOptions(): bool
    {
        return collect($this->blocks ?? [])->contains(fn ($b) => self::options($b) !== []);
    }

    /** Phần đứng trước prompt riêng của Creative. */
    public function prompt(): string
    {
        return self::compose(array_filter($this->blocks ?? [], fn ($b) => ! ($b['pinned'] ?? false)));
    }

    /** Block ghim: đứng sau prompt riêng của Creative. */
    public function pinnedPrompt(): string
    {
        return self::compose(array_filter($this->blocks ?? [], fn ($b) => (bool) ($b['pinned'] ?? false)));
    }
}
