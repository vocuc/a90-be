<?php

namespace App\Http\Resources;

use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiModel */
class AiModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'provider' => [
                'id' => $this->provider->id,
                'code' => $this->provider->code,
                'name' => $this->provider->name,
            ],
            'supported_aspect_ratios' => $this->supported_aspect_ratios,
        ];
    }
}
