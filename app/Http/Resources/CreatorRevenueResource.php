<?php

namespace App\Http\Resources;

use App\Models\CreatorRevenue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreatorRevenue */
class CreatorRevenueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'status' => $this->status,
            'available_at' => $this->available_at,
            'released_at' => $this->released_at,
            'transaction' => [
                'code' => $this->transaction->code,
                'amount' => $this->transaction->amount,
                'creator_share_percent' => (float) $this->transaction->creator_share_percent,
                'platform_fee_amount' => $this->transaction->platform_fee_amount,
                'creative' => [
                    'id' => $this->transaction->creative->id,
                    'title' => $this->transaction->creative->title,
                ],
            ],
            'created_at' => $this->created_at,
        ];
    }
}
