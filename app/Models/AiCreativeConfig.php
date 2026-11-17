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
}
