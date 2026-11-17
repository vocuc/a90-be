<?php

namespace App\Models;

use App\Services\CreativeTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template prompt của Creator: các block { title, content, pinned } ghép thành một prompt.
 * Creative chọn Template thì prompt cuối = block thường + prompt riêng của Creative + block ghim cuối (merge()).
 */
class PromptTemplate extends Model
{
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

    /** Ghép block: "Tiêu đề\nNội dung", cách nhau 1 dòng trống. Khớp frontend promptTemplate.js. */
    public static function compose(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $title = trim((string) ($block['title'] ?? ''));
            $content = trim((string) ($block['content'] ?? ''));
            $part = trim($title !== '' && $content !== '' ? "{$title}\n{$content}" : $title.$content);
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
    public static function merge(array $blocks, ?string $ownPrompt): string
    {
        $pinned = fn (array $b) => (bool) ($b['pinned'] ?? false);

        return CreativeTemplate::mergePrompt(
            self::compose(array_filter($blocks, fn ($b) => ! $pinned($b))),
            $ownPrompt,
            self::compose(array_filter($blocks, $pinned)),
        );
    }

    /** Prompt cuối của Creative dùng Template $id (null / không tìm thấy = chỉ prompt riêng). */
    public static function mergeFor(?int $id, ?string $ownPrompt): string
    {
        $template = $id ? self::query()->find($id) : null;

        return $template ? self::merge($template->blocks ?? [], $ownPrompt) : CreativeTemplate::mergePrompt($ownPrompt);
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
