<?php

namespace App\Services\Shipping;

use App\Enums\ShippingOrderStatus;
use App\Exceptions\BusinessException;
use App\Jobs\ReadShippingOrderImages;
use App\Models\CreatorProfile;
use App\Models\ShippingOrder;
use App\Models\ShippingOrderImage;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Tạo đơn GHN từ ảnh (note/tao-don-ghn-tu-anh.txt ở repo frontend):
 * tải ảnh -> (job chạy ngầm) AI đọc -> khớp địa chỉ với danh mục GHN -> đơn nháp -> người dùng sửa -> gửi GHN.
 */
class ShippingOrderService
{
    /** Cạnh dài tối đa khi lưu ảnh chat: đủ rõ chữ cho AI đọc ảnh chụp màn hình điện thoại. */
    private const IMAGE_MAX_SIDE = 2400;

    private const MIME_TYPES = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public function __construct(private MediaStorage $media, private ShippingOrderReader $reader) {}

    public function ghn(User $user): GhnClient
    {
        $profile = $user->creatorProfile;
        if (! $profile?->hasGhnSettings()) {
            throw BusinessException::ghnSettingsRequired();
        }

        return new GhnClient($profile->ghn_token, (int) $profile->ghn_shop_id);
    }

    public function catalog(User $user): GhnCatalog
    {
        return new GhnCatalog($this->ghn($user));
    }

    /** Gọi thử GHN để kiểm tra token + ShopId rồi mới lưu. */
    public function saveSettings(CreatorProfile $profile, string $token, int $shopId): void
    {
        try {
            $shopIds = (new GhnClient($token, $shopId))->shopIds();
        } catch (GhnException $e) {
            throw ValidationException::withMessages(['token' => $e->isUnauthorized() ? 'Token GHN không đúng hoặc đã hết hạn.' : $e->getMessage()]);
        }
        if (! in_array($shopId, $shopIds, true)) {
            throw ValidationException::withMessages(['shop_id' => 'ShopId không thuộc tài khoản GHN của token này.']);
        }

        $profile->update(['ghn_token' => $token, 'ghn_shop_id' => $shopId]);
    }

    /**
     * Bước 1: lưu ảnh, tạo đơn status reading rồi giao job chạy ngầm cho AI đọc (readImages), không bắt người dùng chờ.
     *
     * @param  UploadedFile[]  $files
     */
    public function createFromImages(User $user, array $files, string $addressType): ShippingOrder
    {
        // Chưa kết nối GHN thì báo ngay, không đợi tới lúc job chạy
        $this->ghn($user);

        $paths = [];
        foreach ($files as $file) {
            [$paths[]] = $this->media->storeOptimizedImage($file, MediaStorage::datedDirectory("shipping-orders/{$user->id}"), [self::IMAGE_MAX_SIDE]);
        }

        return DB::transaction(function () use ($user, $addressType, $paths) {
            $order = ShippingOrder::query()->create([
                'user_id' => $user->id,
                'status' => ShippingOrderStatus::Reading,
                'address_type' => $addressType,
                'item_name' => ShippingOrder::DEFAULT_ITEM_NAME,
                'weight' => ShippingOrder::DEFAULT_WEIGHT,
                'required_note' => ShippingOrder::DEFAULT_REQUIRED_NOTE,
            ]);
            foreach ($paths as $i => $path) {
                $order->images()->create(['path' => $path, 'position' => $i]);
            }
            ReadShippingOrderImages::dispatch($order->id)->afterCommit();

            return $order;
        });
    }

    /**
     * Bước 2-4 (job ReadShippingOrderImages): AI đọc ảnh, khớp địa chỉ, chuyển đơn sang nháp.
     * AI không đọc được -> vẫn thành nháp với các trường trống. Đơn đã bị xoá / không còn reading -> bỏ qua.
     */
    public function readImages(int $orderId): void
    {
        $order = ShippingOrder::query()->with(['images', 'user.creatorProfile'])->find($orderId);
        if ($order?->status !== ShippingOrderStatus::Reading) {
            return;
        }

        $images = $order->images->map(function (ShippingOrderImage $image) {
            $extension = strtolower(pathinfo($image->path, PATHINFO_EXTENSION));

            return ['contents' => $this->media->get($image->path), 'mime' => self::MIME_TYPES[$extension] ?? 'image/jpeg'];
        })->all();

        $ai = $this->reader->read($images) ?? [];
        $fields = $this->draftFields($this->catalog($order->user), $order->address_type, $ai);

        // Người dùng xoá đơn trong lúc AI đọc -> không còn đơn để cập nhật
        ShippingOrder::query()
            ->whereKey($order->id)
            ->where('status', ShippingOrderStatus::Reading)
            ->first()
            ?->update([...$fields, 'status' => ShippingOrderStatus::Draft]);
    }

    /**
     * Sửa đơn nháp / đơn lỗi: gửi trường nào sửa trường đó. Tỉnh / quận / phường gửi theo id (code), tên lấy từ
     * danh mục GHN. Đổi cấp trên mà không gửi cấp dưới -> cấp dưới bị xoá để chọn lại.
     */
    public function update(User $user, ShippingOrder $order, array $data): ShippingOrder
    {
        $this->ensureEditable($order);

        $location = array_intersect_key($data, array_flip(['address_type', 'to_province_id', 'to_district_id', 'to_ward_code']));
        if ($location) {
            $data = [...$data, ...$this->resolveLocation($user, $order, $location)];
        }

        // Người dùng đã sửa trường nào thì bỏ tô vàng trường đó
        $edited = array_keys($data);
        $order->fill($data);
        $order->low_confidence_fields = array_values(array_diff($order->low_confidence_fields ?? [], $edited));
        $order->save();

        return $order;
    }

    /** Bước 5: gửi đơn sang GHN. GHN từ chối -> đơn failed (sửa rồi gửi lại được) + 422 lời báo của GHN. */
    public function submit(User $user, ShippingOrder $order, int $paymentTypeId): ShippingOrder
    {
        $this->ensureEditable($order);
        $this->ensureComplete($order);
        $ghn = $this->ghn($user);

        $order->update(['payment_type_id' => $paymentTypeId]);

        try {
            $result = $ghn->createOrder($this->payload($order));
        } catch (GhnException $e) {
            $order->update(['status' => ShippingOrderStatus::Failed, 'error_message' => $e->getMessage()]);
            throw BusinessException::ghnError($e->getMessage());
        }

        $order->update([
            'status' => ShippingOrderStatus::Submitted,
            'ghn_order_code' => $result['order_code'],
            'shipping_fee' => $result['total_fee'],
            'expected_delivery_time' => $result['expected_delivery_time'],
            'error_message' => null,
            'submitted_at' => now(),
        ]);

        return $order;
    }

    /** Xoá được cả đơn đang đọc ảnh (job gặp đơn đã xoá thì bỏ qua). */
    public function delete(ShippingOrder $order): void
    {
        if ($order->status === ShippingOrderStatus::Submitted) {
            throw BusinessException::invalidState('Đơn đã được gửi sang GHN, không xoá được nữa.');
        }
        $paths = $order->images()->pluck('path');
        $order->delete();
        $paths->each(fn ($path) => $this->media->delete($path));
    }

    /** Kết quả AI -> các cột của đơn nháp; phần địa chỉ AI đọc được nhưng không khớp danh mục -> tô vàng. */
    private function draftFields(GhnCatalog $catalog, string $addressType, array $ai): array
    {
        $low = [];
        foreach ($ai['low_confidence_fields'] ?? [] as $field) {
            $low = [...$low, ...($field === 'address' ? $this->locationFields($addressType, withStreet: true) : [$field])];
        }

        $match = ['province' => null, 'district' => null, 'ward' => null];
        $hasLocation = filled($ai['province'] ?? null) || filled($ai['ward'] ?? null);
        if ($hasLocation) {
            try {
                $match = $catalog->match($addressType, $ai['province'] ?? null, $ai['district'] ?? null, $ai['ward'] ?? null);
            } catch (GhnException $e) {
                Log::warning('GHN catalog match failed', ['message' => $e->getMessage()]);
            }
            $levels = ['to_province_id' => 'province', 'to_district_id' => 'district', 'to_ward_code' => 'ward'];
            foreach ($this->locationFields($addressType) as $field) {
                if (! $match[$levels[$field]]) {
                    $low[] = $field;
                }
            }
        }

        $cod = $ai['cod_amount'] ?? null;

        return [
            'to_name' => self::clean($ai['to_name'] ?? null, 255),
            'to_phone' => self::phone($ai['to_phone'] ?? null),
            'to_address' => self::clean($ai['street'] ?? null, 500),
            'to_province_id' => $match['province']['id'] ?? null,
            'to_province_name' => $match['province']['name'] ?? null,
            'to_district_id' => $match['district']['id'] ?? null,
            'to_district_name' => $match['district']['name'] ?? null,
            'to_ward_code' => $match['ward']['code'] ?? null,
            'to_ward_name' => $match['ward']['name'] ?? null,
            'raw_address' => self::clean($ai['raw_address'] ?? null, 2000),
            'cod_amount' => is_int($cod) && $cod >= 0 ? min($cod, ShippingOrder::MAX_COD) : 0,
            'note' => self::clean($ai['note'] ?? null, 2000),
            'low_confidence_fields' => array_values(array_unique(array_intersect($low, ShippingOrder::AI_FIELDS))),
        ];
    }

    /** @return string[] */
    private function locationFields(string $addressType, bool $withStreet = false): array
    {
        return array_values(array_filter([
            $withStreet ? 'to_address' : null,
            'to_province_id',
            $addressType === ShippingOrder::ADDRESS_OLD ? 'to_district_id' : null,
            'to_ward_code',
        ]));
    }

    /** Tỉnh / quận / phường mới -> id + tên theo danh mục GHN. */
    private function resolveLocation(User $user, ShippingOrder $order, array $input): array
    {
        $catalog = $this->catalog($user);
        $type = $input['address_type'] ?? $order->address_type;
        $typeChanged = $type !== $order->address_type;

        $provinceId = array_key_exists('to_province_id', $input) ? $input['to_province_id'] : ($typeChanged ? null : $order->to_province_id);
        $provinceChanged = $typeChanged || (int) $provinceId !== (int) $order->to_province_id;
        $districtId = $type === ShippingOrder::ADDRESS_NEW ? null
            : (array_key_exists('to_district_id', $input) ? $input['to_district_id'] : ($provinceChanged ? null : $order->to_district_id));
        $districtChanged = $provinceChanged || (int) $districtId !== (int) $order->to_district_id;
        $wardCode = array_key_exists('to_ward_code', $input) ? $input['to_ward_code'] : ($districtChanged ? null : $order->to_ward_code);

        try {
            $province = $provinceId ? GhnCatalog::byKey($catalog->provinces($type), $provinceId) : null;
            if ($provinceId && ! $province) {
                throw ValidationException::withMessages(['to_province_id' => 'Tỉnh / thành phố không hợp lệ.']);
            }
            $district = $districtId && $province ? GhnCatalog::byKey($catalog->districts($province['id']), $districtId) : null;
            if ($districtId && ! $district) {
                throw ValidationException::withMessages(['to_district_id' => 'Quận / huyện không hợp lệ.']);
            }
            $parent = $type === ShippingOrder::ADDRESS_NEW ? $province : $district;
            $ward = $wardCode && $parent ? GhnCatalog::byKey($catalog->wards($type, $parent['id']), $wardCode) : null;
            if ($wardCode && ! $ward) {
                throw ValidationException::withMessages(['to_ward_code' => 'Phường / xã không hợp lệ.']);
            }
        } catch (GhnException $e) {
            throw BusinessException::ghnError($e->getMessage());
        }

        return [
            'address_type' => $type,
            'to_province_id' => $province['id'] ?? null,
            'to_province_name' => $province['name'] ?? null,
            'to_district_id' => $district['id'] ?? null,
            'to_district_name' => $district['name'] ?? null,
            'to_ward_code' => $ward['code'] ?? null,
            'to_ward_name' => $ward['name'] ?? null,
        ];
    }

    private function ensureEditable(ShippingOrder $order): void
    {
        if ($order->status === ShippingOrderStatus::Reading) {
            throw BusinessException::invalidState('AI đang đọc ảnh, vui lòng chờ trong giây lát.');
        }
        if (! $order->status->isEditable()) {
            throw BusinessException::invalidState('Đơn đã được gửi sang GHN, không sửa được nữa.');
        }
    }

    /** Thiếu trường bắt buộc -> 422 theo từng trường, chưa gọi GHN. */
    private function ensureComplete(ShippingOrder $order): void
    {
        $errors = array_filter([
            'to_name' => blank($order->to_name) ? 'Vui lòng nhập tên người nhận.' : null,
            'to_phone' => ! preg_match('/^0\d{9,10}$/', (string) $order->to_phone) ? 'Số điện thoại không hợp lệ.' : null,
            'to_address' => blank($order->to_address) ? 'Vui lòng nhập số nhà, tên đường.' : null,
            'to_province_id' => blank($order->to_province_name) ? 'Vui lòng chọn tỉnh / thành phố.' : null,
            'to_district_id' => ! $order->isNewAddress() && blank($order->to_district_name) ? 'Vui lòng chọn quận / huyện.' : null,
            'to_ward_code' => blank($order->to_ward_name) ? 'Vui lòng chọn phường / xã.' : null,
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function payload(ShippingOrder $order): array
    {
        $new = $order->isNewAddress();
        $fullAddress = implode(', ', array_filter([$order->to_address, $order->to_ward_name, $order->to_district_name, $order->to_province_name]));

        return array_filter([
            'client_order_code' => $order->clientOrderCode(),
            'payment_type_id' => $order->payment_type_id,
            'required_note' => $order->required_note,
            'note' => $order->note,
            'to_name' => $order->to_name,
            'to_phone' => $order->to_phone,
            'to_address' => $fullAddress,
            'is_new_to_address' => $new,
            'to_province_name' => $order->to_province_name,
            'to_district_name' => $new ? null : $order->to_district_name,
            'to_ward_name' => $order->to_ward_name,
            // Địa chỉ cũ: gửi kèm id / code GHN để khớp chắc chắn
            'to_district_id' => $new ? null : $order->to_district_id,
            'to_ward_code' => $new ? null : $order->to_ward_code,
            'cod_amount' => $order->cod_amount,
            'weight' => $order->weight,
            'service_type_id' => 2,
            'content' => $order->item_name,
            'items' => [['name' => $order->item_name, 'quantity' => 1, 'weight' => $order->weight]],
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Chỉ giữ chữ số, +84 / 84 -> 0. */
    private static function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (str_starts_with($digits, '84') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits === '' ? null : substr($digits, 0, 20);
    }
}
