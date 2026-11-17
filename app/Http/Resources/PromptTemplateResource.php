<?php

namespace App\Http\Resources;

use App\Models\PromptTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Template prompt của Creator.
 *
 * @mixin PromptTemplate
 */
class PromptTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'blocks' => $this->blocks ?? [],
            // Prompt đã ghép các block, đứng trước prompt riêng của Creative
            'prompt' => $this->prompt(),
            // Số Creative đang dùng
            'creative_count' => $this->configs_count ?? null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
