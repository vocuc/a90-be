<?php

namespace App\Http\Resources;

use App\Models\CreatorApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * KHÔNG BAO GIỜ trả api_key, chỉ trả 4 ký tự cuối.
 *
 * @mixin CreatorApiKey
 */
class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'label' => $this->label,
            'key_hint' => '••••'.$this->key_hint,
            'status' => $this->status,
            'cooldown_until' => $this->cooldown_until,
            'last_used_at' => $this->last_used_at,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
        ];
    }
}
