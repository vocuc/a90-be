<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template prompt của Creator: các block { title, content } ghép thành một prompt.
 * Creative chọn Template thì prompt cuối = prompt Template + prompt riêng của Creative (CreativeTemplate::mergePrompt).
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

    public function prompt(): string
    {
        return self::compose($this->blocks ?? []);
    }
}
