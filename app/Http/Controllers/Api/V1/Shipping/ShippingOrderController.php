<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Enums\ShippingOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShippingOrderResource;
use App\Models\ShippingOrder;
use App\Services\Shipping\ShippingOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Đơn vận chuyển GHN tạo từ ảnh chat (note/tao-don-ghn-tu-anh.txt ở repo frontend). Đơn của người khác -> 404.
 * AI đọc ảnh không trừ credit.
 */
class ShippingOrderController extends Controller
{
    public function __construct(private ShippingOrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(ShippingOrderStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $orders = ShippingOrder::query()
            ->where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with('images')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return ShippingOrderResource::collection($orders);
    }

    public function show(Request $request, int $order): ShippingOrderResource
    {
        return $this->resource($this->findOrder($request, $order));
    }

    /** Bước 1: tải 1-10 ảnh chat của 1 đơn -> đơn status reading, AI đọc ở job chạy ngầm rồi chuyển sang nháp. */
    public function parse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.ShippingOrder::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'address_type' => ['required', Rule::in(ShippingOrder::ADDRESS_TYPES)],
        ], [
            'images.required' => 'Vui lòng chọn ít nhất 1 ảnh.',
            'images.max' => 'Tối đa '.ShippingOrder::MAX_IMAGES.' ảnh mỗi đơn.',
            'images.*.max' => 'Mỗi ảnh tối đa 10MB.',
            'images.*.mimes' => 'Chỉ nhận ảnh JPG, PNG hoặc WebP.',
        ]);

        $order = $this->orders->createFromImages($request->user(), $data['images'], $data['address_type']);

        return $this->resource($order)->response()->setStatusCode(201);
    }

    public function update(Request $request, int $order): ShippingOrderResource
    {
        $order = $this->findOrder($request, $order);
        $data = $request->validate([
            'address_type' => ['sometimes', Rule::in(ShippingOrder::ADDRESS_TYPES)],
            'to_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'to_phone' => ['sometimes', 'nullable', 'string', 'regex:/^0\d{9,10}$/'],
            'to_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'to_province_id' => ['sometimes', 'nullable', 'integer'],
            'to_district_id' => ['sometimes', 'nullable', 'integer'],
            'to_ward_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'item_name' => ['sometimes', 'required', 'string', 'max:500'],
            'cod_amount' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.ShippingOrder::MAX_COD],
            'weight' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.ShippingOrder::MAX_WEIGHT],
            'required_note' => ['sometimes', 'required', Rule::in(ShippingOrder::REQUIRED_NOTES)],
            'payment_type_id' => ['sometimes', 'nullable', Rule::in(ShippingOrder::PAYMENT_TYPES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [
            'to_phone.regex' => 'Số điện thoại không hợp lệ (10-11 số, bắt đầu bằng 0).',
            'item_name.required' => 'Vui lòng nhập tên sản phẩm.',
            'cod_amount.max' => 'Tiền thu hộ tối đa 50.000.000đ.',
            'weight.max' => 'Khối lượng phải dưới 20kg.',
        ]);

        return $this->resource($this->orders->update($request->user(), $order, $data));
    }

    /** Bước 5: gửi GHN, bắt buộc chọn ai trả ship (1 = shop, 2 = khách). */
    public function submit(Request $request, int $order): ShippingOrderResource
    {
        $order = $this->findOrder($request, $order);
        $data = $request->validate(
            ['payment_type_id' => ['required', Rule::in(ShippingOrder::PAYMENT_TYPES)]],
            ['payment_type_id.required' => 'Vui lòng chọn ai trả phí ship.'],
        );

        return $this->resource($this->orders->submit($request->user(), $order, (int) $data['payment_type_id']));
    }

    public function destroy(Request $request, int $order): Response
    {
        $this->orders->delete($this->findOrder($request, $order));

        return response()->noContent();
    }

    private function findOrder(Request $request, int $id): ShippingOrder
    {
        return ShippingOrder::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function resource(ShippingOrder $order): ShippingOrderResource
    {
        return new ShippingOrderResource($order->fresh('images'));
    }
}
