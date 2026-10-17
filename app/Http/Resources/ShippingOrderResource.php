<?php

namespace App\Http\Resources;

use App\Models\ShippingOrder;
use App\Models\ShippingOrderImage;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cần load: images.
 *
 * @mixin ShippingOrder
 */
class ShippingOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = app(MediaStorage::class);

        return [
            'id' => $this->id,
            'code' => $this->clientOrderCode(),
            'status' => $this->status,
            'address_type' => $this->address_type,
            'to_name' => $this->to_name,
            'to_phone' => $this->to_phone,
            'to_address' => $this->to_address,
            'to_province_id' => $this->to_province_id,
            'to_province_name' => $this->to_province_name,
            'to_district_id' => $this->to_district_id,
            'to_district_name' => $this->to_district_name,
            'to_ward_code' => $this->to_ward_code,
            'to_ward_name' => $this->to_ward_name,
            'raw_address' => $this->raw_address,
            'item_name' => $this->item_name,
            'cod_amount' => $this->cod_amount,
            'weight' => $this->weight,
            'required_note' => $this->required_note,
            'payment_type_id' => $this->payment_type_id,
            'note' => $this->note,
            'low_confidence_fields' => $this->low_confidence_fields ?? [],
            'images' => $this->images->map(fn (ShippingOrderImage $image) => [
                'id' => $image->id,
                'url' => $media->url($image->path),
            ])->values(),
            'ghn_order_code' => $this->ghn_order_code,
            'shipping_fee' => $this->shipping_fee,
            'expected_delivery_time' => $this->expected_delivery_time,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
