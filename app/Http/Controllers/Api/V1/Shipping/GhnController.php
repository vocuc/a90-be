<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\ShippingOrder;
use App\Services\Shipping\GhnCatalog;
use App\Services\Shipping\GhnException;
use App\Services\Shipping\ShippingOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kết nối GHN của shop (Token + ShopId) và danh mục địa chỉ GHN. Token chỉ lưu ở backend, frontend chỉ thấy 4 ký tự cuối.
 * Địa chỉ lấy hàng: dùng địa chỉ đã đăng ký của ShopId bên GHN, a51 không lưu.
 */
class GhnController extends Controller
{
    public function __construct(private ShippingOrderService $orders) {}

    public function settings(Request $request): JsonResponse
    {
        return $this->settingsResponse($request->user()->creatorProfile);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'shop_id' => ['required', 'integer', 'min:1'],
        ], [
            'token.required' => 'Vui lòng nhập Token GHN.',
            'shop_id.required' => 'Vui lòng nhập ShopId.',
            'shop_id.integer' => 'ShopId là một dãy số.',
        ]);

        $profile = $request->user()->creatorProfile;
        $this->orders->saveSettings($profile, trim($data['token']), (int) $data['shop_id']);

        return $this->settingsResponse($profile->fresh());
    }

    public function provinces(Request $request): JsonResponse
    {
        $data = $request->validate(['address_type' => ['required', Rule::in(ShippingOrder::ADDRESS_TYPES)]]);

        return $this->catalogResponse($request, fn (GhnCatalog $c) => self::items($c->provinces($data['address_type']), 'id'));
    }

    public function districts(Request $request): JsonResponse
    {
        $data = $request->validate(['province_id' => ['required', 'integer']]);

        return $this->catalogResponse($request, fn (GhnCatalog $c) => self::items($c->districts($data['province_id']), 'id'));
    }

    /** Địa chỉ cũ: phường theo quận (district_id). Địa chỉ mới: phường theo tỉnh (province_id + address_type=new). */
    public function wards(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_type' => ['nullable', Rule::in(ShippingOrder::ADDRESS_TYPES)],
            'district_id' => ['required_unless:address_type,new', 'nullable', 'integer'],
            'province_id' => ['required_if:address_type,new', 'nullable', 'integer'],
        ]);
        $new = ($data['address_type'] ?? null) === ShippingOrder::ADDRESS_NEW;

        return $this->catalogResponse($request, fn (GhnCatalog $c) => self::items(
            $c->wards($new ? 'new' : 'old', $new ? $data['province_id'] : $data['district_id']), 'code'));
    }

    private function catalogResponse(Request $request, callable $load): JsonResponse
    {
        try {
            return response()->json(['data' => $load($this->orders->catalog($request->user()))]);
        } catch (GhnException $e) {
            throw new BusinessException('GHN_ERROR', $e->getMessage(), 502);
        }
    }

    private static function items(array $items, string $key): array
    {
        return array_map(fn ($item) => [$key => $item[$key], 'name' => $item['name']], $items);
    }

    private function settingsResponse(CreatorProfile $profile): JsonResponse
    {
        $hasToken = (bool) $profile->ghn_token;

        return response()->json(['data' => [
            'shop_id' => $profile->ghn_shop_id,
            'has_token' => $hasToken,
            'token_last4' => $hasToken ? substr($profile->ghn_token, -4) : null,
        ]]);
    }
}
