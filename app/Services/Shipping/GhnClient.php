<?php

namespace App\Services\Shipping;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Gọi API GHN bằng token + ShopId của một shop (https://developer.ghn.vn).
 * Lỗi phía GHN (code != 200, không kết nối được) -> GhnException mang lời báo của GHN.
 */
class GhnClient
{
    public function __construct(private string $token, private int $shopId) {}

    /**
     * Các shop của token (kiểm tra token + ShopId trước khi lưu cài đặt).
     *
     * @return int[] id các shop
     */
    public function shopIds(): array
    {
        $data = $this->call('post', 'v2/shop/all', ['offset' => 0, 'limit' => 200], withShop: false);

        return array_map(fn ($shop) => (int) $shop['_id'], $data['shops'] ?? []);
    }

    /**
     * Danh mục tỉnh: old -> ProvinceID / ProvinceName, new (2 cấp, từ 01/07/2025) -> _id / name.
     *
     * @return array<int, array{id: int, name: string, aliases: string[]}>
     */
    public function provinces(string $addressType): array
    {
        if ($addressType === 'new') {
            return array_map(fn ($p) => ['id' => (int) $p['_id'], 'name' => $p['name'], 'aliases' => $p['extension_names'] ?? []],
                $this->call('get', 'v3/master-data/province/all', ['offset' => 0, 'limit' => 200]) ?? []);
        }

        return array_map(fn ($p) => ['id' => (int) $p['ProvinceID'], 'name' => $p['ProvinceName'], 'aliases' => $p['NameExtension'] ?? []],
            $this->call('get', 'master-data/province') ?? []);
    }

    /** @return array<int, array{id: int, name: string, aliases: string[]}> (chỉ địa chỉ cũ) */
    public function districts(int $provinceId): array
    {
        return array_map(fn ($d) => ['id' => (int) $d['DistrictID'], 'name' => $d['DistrictName'], 'aliases' => $d['NameExtension'] ?? []],
            $this->call('get', 'master-data/district', ['province_id' => $provinceId]) ?? []);
    }

    /** @return array<int, array{code: string, name: string, aliases: string[]}> phường theo quận (địa chỉ cũ) */
    public function wardsByDistrict(int $districtId): array
    {
        return array_map(fn ($w) => ['code' => (string) $w['WardCode'], 'name' => $w['WardName'], 'aliases' => $w['NameExtension'] ?? []],
            $this->call('get', 'master-data/ward', ['district_id' => $districtId]) ?? []);
    }

    /** @return array<int, array{code: string, name: string, aliases: string[]}> phường theo tỉnh (địa chỉ mới) */
    public function wardsByProvince(int $provinceId): array
    {
        // GHN giới hạn 200 phường / lần, tỉnh lớn có hơn 200 phường -> lấy tới khi hết
        $wards = [];
        for ($offset = 0; ; $offset += 200) {
            $page = $this->call('get', 'v3/master-data/ward/all-by-province-id',
                ['province_id' => $provinceId, 'offset' => $offset, 'limit' => 200]) ?? [];
            foreach ($page as $w) {
                $wards[] = ['code' => (string) $w['_id'], 'name' => $w['name'], 'aliases' => $w['extension_names'] ?? []];
            }
            if (count($page) < 200) {
                return $wards;
            }
        }
    }

    /**
     * Tạo đơn (POST v2/shipping-order/create).
     *
     * @return array{order_code: string, total_fee: int|null, expected_delivery_time: string|null}
     */
    public function createOrder(array $payload): array
    {
        $data = $this->call('post', 'v2/shipping-order/create', $payload);

        return [
            'order_code' => (string) $data['order_code'],
            'total_fee' => isset($data['total_fee']) ? (int) $data['total_fee'] : null,
            'expected_delivery_time' => $data['expected_delivery_time'] ?? null,
        ];
    }

    private function call(string $method, string $path, array $params = [], bool $withShop = true): mixed
    {
        try {
            $response = $this->request($withShop)->{$method}($path, $params);
        } catch (ConnectionException) {
            throw new GhnException('Không kết nối được GHN, vui lòng thử lại sau.');
        }

        $body = $response->json();
        if (! $response->successful() || ($body['code'] ?? null) !== 200) {
            throw new GhnException(
                $body['message_display'] ?? $body['message'] ?? 'GHN không phản hồi, vui lòng thử lại sau.',
                $response->status(),
                $body['code_message'] ?? null,
            );
        }

        return $body['data'] ?? null;
    }

    private function request(bool $withShop): PendingRequest
    {
        return Http::baseUrl(config('services.ghn.base_url'))
            ->timeout(config('services.ghn.timeout'))
            ->acceptJson()
            ->withHeaders(array_filter([
                'Token' => $this->token,
                'ShopId' => $withShop ? (string) $this->shopId : null,
            ]));
    }
}
