<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreativeConfig extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
        ];
    }

    public function creative(): BelongsTo
    {
        return $this->belongsTo(AiCreative::class, 'creative_id');
    }

    public function promptTemplate(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    /** Prompt riêng của Creative (không gồm phần Template). Creative cũ chưa có cột này thì prompt_template là của riêng nó. */
    public function ownPrompt(): string
    {
        return (string) ($this->own_prompt_template ?? ($this->prompt_template_id ? '' : $this->prompt_template));
    }

    /**
     * Prompt dùng cho 1 lần tạo ảnh: Template có block chứa option thì gộp lại, mỗi block lấy ngẫu nhiên 1 option.
     * Không có option -> dùng bản gộp đã lưu (prompt_template).
     */
    public function generationPrompt(): string
    {
        $template = $this->prompt_template_id ? $this->promptTemplate : null;

        return $template?->hasOptions()
            ? PromptTemplate::merge($template->blocks ?? [], $this->ownPrompt(), PromptTemplate::randomPick())
            : (string) $this->prompt_template;
    }
}
