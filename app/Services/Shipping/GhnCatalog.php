<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Danh mục tỉnh / quận / phường của GHN (cache chung cho mọi shop vì danh mục giống nhau) và khớp địa chỉ chữ
 * AI đọc được với danh mục. Khớp theo tên đã bỏ dấu, bỏ tiền tố (tỉnh, quận, phường...) và tên phụ GHN trả về.
 */
class GhnCatalog
{
    private const PREFIXES = [
        'thanh pho', 'tinh', 'quan', 'huyen', 'thi xa', 'thi tran', 'phuong', 'xa', 'dac khu',
    ];

    // Viết tắt: chỉ bỏ khi theo sau là dấu chấm, khoảng trắng hoặc số ("Q1", "P.12", "TP HCM"), không đụng "Phú Nhuận"
    private const SHORT_PREFIX = '/^(tp|tx|tt|q|h|p|x)(\.|\s+|(?=\d))\s*/';

    private const ALIASES = [
        'hcm' => 'ho chi minh', 'sai gon' => 'ho chi minh', 'sg' => 'ho chi minh', 'hn' => 'ha noi',
    ];

    public function __construct(private GhnClient $ghn) {}

    /** @return array<int, array{id: int, name: string, aliases: string[]}> */
    public function provinces(string $addressType): array
    {
        return $this->remember("provinces:{$addressType}", fn () => $this->ghn->provinces($addressType));
    }

    /** @return array<int, array{id: int, name: string, aliases: string[]}> */
    public function districts(int $provinceId): array
    {
        return $this->remember("districts:{$provinceId}", fn () => $this->ghn->districts($provinceId));
    }

    /** @return array<int, array{code: string, name: string, aliases: string[]}> */
    public function wards(string $addressType, int $parentId): array
    {
        return $addressType === 'new'
            ? $this->remember("wards:new:{$parentId}", fn () => $this->ghn->wardsByProvince($parentId))
            : $this->remember("wards:old:{$parentId}", fn () => $this->ghn->wardsByDistrict($parentId));
    }

    /**
     * Khớp địa chỉ chữ với danh mục. Phần nào không khớp chắc chắn (không có hoặc nhiều kết quả) -> null,
     * các cấp dưới cũng null.
     *
     * @return array{province: ?array, district: ?array, ward: ?array}
     */
    public function match(string $addressType, ?string $province, ?string $district, ?string $ward): array
    {
        $result = ['province' => null, 'district' => null, 'ward' => null];

        $result['province'] = $this->find($this->provinces($addressType), $province);
        if (! $result['province']) {
            return $result;
        }

        if ($addressType === 'new') {
            $result['ward'] = $this->find($this->wards('new', $result['province']['id']), $ward);

            return $result;
        }

        $result['district'] = $this->find($this->districts($result['province']['id']), $district);
        if ($result['district']) {
            $result['ward'] = $this->find($this->wards('old', $result['district']['id']), $ward);
        }

        return $result;
    }

    /** Tìm đúng 1 mục theo id (tỉnh / quận) hoặc code (phường). */
    public static function byKey(array $items, int|string|null $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }
        foreach ($items as $item) {
            if ((string) ($item['id'] ?? $item['code']) === (string) $key) {
                return $item;
            }
        }

        return null;
    }

    private function find(array $items, ?string $text): ?array
    {
        $needle = self::normalize($text);
        if ($needle === '') {
            return null;
        }

        $matches = array_values(array_filter($items, function ($item) use ($needle) {
            foreach ([$item['name'], ...$item['aliases']] as $name) {
                if (self::normalize($name) === $needle) {
                    return true;
                }
            }

            return false;
        }));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** "Thành phố Hồ Chí Minh" / "TP. HCM" -> "ho chi minh"; "Phường 01" / "P1" -> "1". */
    public static function normalize(?string $name): string
    {
        $value = Str::lower(Str::ascii((string) $name));
        $value = trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
        $value = preg_replace(self::SHORT_PREFIX, '', $value);
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix.' ')) {
                $value = substr($value, strlen($prefix) + 1);
                break;
            }
        }
        $value = preg_replace('/\b0+(\d)/', '$1', trim($value));

        return self::ALIASES[$value] ?? $value;
    }

    private function remember(string $key, callable $load): array
    {
        $cacheKey = 'ghn:catalog:'.$key;
        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        // Không cache danh sách rỗng (GHN lỗi tạm / id sai) để lần sau gọi lại
        $items = $load();
        if ($items) {
            Cache::put($cacheKey, $items, now()->addHours(config('services.ghn.catalog_cache_hours')));
        }

        return $items;
    }
}
