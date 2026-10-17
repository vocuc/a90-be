<?php

namespace Tests\Feature;

use App\Jobs\ReadShippingOrderImages;
use App\Models\ShippingOrder;
use App\Models\User;
use App\Services\Shipping\GhnCatalog;
use App\Services\Shipping\ShippingOrderReader;
use App\Services\Shipping\ShippingOrderService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShippingOrderTest extends TestCase
{
    private const GHN = 'https://online-gateway.ghn.vn/shiip/public-api/';

    /** Kết quả AI giả trả về cho lần đọc ảnh tiếp theo (null = AI không đọc được). */
    private ?array $aiResult = null;

    /** @var array<int, array> ảnh AI giả đã nhận */
    private array $aiCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ShippingOrderReader::class, new class($this) implements ShippingOrderReader
        {
            public function __construct(private ShippingOrderTest $test) {}

            public function read(array $images): ?array
            {
                return $this->test->fakeRead($images);
            }
        });

        Http::fake([
            self::GHN.'v2/shop/all' => fn (Request $r) => $r->header('Token')[0] === 'good-token'
                ? Http::response(['code' => 200, 'data' => ['shops' => [['_id' => 885, 'name' => 'Shop A']]]])
                : Http::response(['code' => 401, 'message' => 'Token is not valid!'], 401),
            self::GHN.'master-data/province' => Http::response(['code' => 200, 'data' => [
                ['ProvinceID' => 202, 'ProvinceName' => 'Hồ Chí Minh', 'NameExtension' => ['TP.HCM', 'Sài Gòn']],
                ['ProvinceID' => 201, 'ProvinceName' => 'Hà Nội'],
            ]]),
            self::GHN.'master-data/district*' => Http::response(['code' => 200, 'data' => [
                ['DistrictID' => 1442, 'DistrictName' => 'Quận 1'],
                ['DistrictID' => 1443, 'DistrictName' => 'Quận 2'],
            ]]),
            self::GHN.'master-data/ward*' => Http::response(['code' => 200, 'data' => [
                ['WardCode' => '20101', 'WardName' => 'Phường Bến Nghé'],
                ['WardCode' => '20102', 'WardName' => 'Phường Bến Thành'],
            ]]),
            self::GHN.'v3/master-data/province/all*' => Http::response(['code' => 200, 'data' => [
                ['_id' => 1000001, 'name' => 'Hồ Chí Minh', 'extension_names' => ['tp.hồ chí minh', 'hcm']],
            ]]),
            self::GHN.'v3/master-data/ward/all-by-province-id*' => Http::response(['code' => 200, 'data' => [
                ['_id' => 1003001, 'name' => 'Phường Sài Gòn', 'extension_names' => []],
            ]]),
            self::GHN.'v2/shipping-order/create' => fn (Request $r) => $r['to_phone'] === '0999999999'
                ? Http::response(['code' => 400, 'message' => 'Số điện thoại không hợp lệ', 'code_message' => 'PHONE_INVALID'], 400)
                : Http::response(['code' => 200, 'data' => [
                    'order_code' => 'GHN123', 'total_fee' => 33000, 'expected_delivery_time' => '2026-10-20T16:59:59Z',
                ]]),
        ]);
    }

    public function fakeRead(array $images): ?array
    {
        $this->aiCalls[] = $images;

        return $this->aiResult;
    }

    // ---- Helpers ----

    private function connectedCreator(): User
    {
        $user = $this->creator();
        $user->creatorProfile->update(['ghn_token' => 'good-token', 'ghn_shop_id' => 885]);

        return $user->fresh();
    }

    private function parse(User $user, string $addressType = 'old', int $images = 2): TestResponse
    {
        return $this->actingAs($user)->post('/api/v1/creator/shipping-orders/parse', [
            'address_type' => $addressType,
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("chat{$i}.png", 400, 800), range(1, $images)),
        ], ['Accept' => 'application/json']);
    }

    private function aiOrder(array $extra = []): array
    {
        return array_merge([
            'to_name' => 'Nguyễn Văn A',
            'to_phone' => '+84 912 345 678',
            'street' => '12 Lê Lợi',
            'ward' => 'P. Bến Nghé',
            'district' => 'Q1',
            'province' => 'TP. HCM',
            'raw_address' => '12 Lê Lợi, P. Bến Nghé, Q1, TP. HCM',
            'cod_amount' => 350000,
            'note' => 'Gọi trước khi giao',
            'low_confidence_fields' => ['cod_amount'],
        ], $extra);
    }

    // ---- Cài đặt GHN ----

    public function test_settings_are_verified_with_ghn_and_token_is_never_returned(): void
    {
        $user = $this->creator();

        $this->actingAs($user)->getJson('/api/v1/creator/shipping/ghn-settings')
            ->assertOk()->assertJson(['data' => ['shop_id' => null, 'has_token' => false]]);

        $this->putJson('/api/v1/creator/shipping/ghn-settings', ['token' => 'bad-token', 'shop_id' => 885])
            ->assertStatus(422)->assertJsonValidationErrors('token');
        $this->putJson('/api/v1/creator/shipping/ghn-settings', ['token' => 'good-token', 'shop_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('shop_id');

        $this->putJson('/api/v1/creator/shipping/ghn-settings', ['token' => 'good-token', 'shop_id' => 885])
            ->assertOk()
            ->assertExactJson(['data' => ['shop_id' => 885, 'has_token' => true, 'token_last4' => 'oken']]);

        $this->assertSame('good-token', $user->creatorProfile->fresh()->ghn_token);
        $this->assertNotSame('good-token', $user->creatorProfile->fresh()->getRawOriginal('ghn_token'));
        $this->getJson('/api/v1/creator/profile')->assertOk()->assertJsonMissingPath('data.ghn_token');
    }

    // ---- Tải ảnh + AI đọc ----

    public function test_parse_requires_ghn_settings(): void
    {
        $this->parse($this->creator())->assertStatus(400)->assertJsonPath('code', 'GHN_SETTINGS_REQUIRED');
        $this->assertSame([], $this->aiCalls);
    }

    public function test_parse_reads_all_images_in_one_call_and_matches_old_address(): void
    {
        $this->aiResult = $this->aiOrder();
        $user = $this->connectedCreator();

        $response = $this->parse($user, 'old', 3)->assertCreated();

        $this->assertCount(1, $this->aiCalls);
        $this->assertCount(3, $this->aiCalls[0]);
        $this->assertStringStartsWith('image/', $this->aiCalls[0][0]['mime']);
        $response->assertJson(['data' => [
            'status' => 'draft',
            'address_type' => 'old',
            'to_name' => 'Nguyễn Văn A',
            'to_phone' => '0912345678',
            'to_address' => '12 Lê Lợi',
            'to_province_id' => 202,
            'to_district_id' => 1442,
            'to_ward_code' => '20101',
            'to_ward_name' => 'Phường Bến Nghé',
            'item_name' => 'Hàng hóa dịch vụ',
            'cod_amount' => 350000,
            'weight' => 2000,
            'required_note' => 'CHOXEMHANGKHONGTHU',
            'payment_type_id' => null,
            'low_confidence_fields' => ['cod_amount'],
        ]]);
        $this->assertCount(3, $response->json('data.images'));
    }

    public function test_parse_matches_new_address_without_district(): void
    {
        $this->aiResult = $this->aiOrder(['ward' => 'phường Sài Gòn', 'district' => null, 'province' => 'Thành phố Hồ Chí Minh']);

        $this->parse($this->connectedCreator(), 'new')->assertCreated()->assertJson(['data' => [
            'address_type' => 'new',
            'to_province_id' => 1000001,
            'to_district_id' => null,
            'to_ward_code' => '1003001',
            'to_ward_name' => 'Phường Sài Gòn',
        ]]);
    }

    public function test_unmatched_address_parts_are_left_empty_and_flagged(): void
    {
        $this->aiResult = $this->aiOrder(['district' => 'Quận 9', 'low_confidence_fields' => []]);

        $data = $this->parse($this->connectedCreator())->assertCreated()->json('data');

        $this->assertSame(202, $data['to_province_id']);
        $this->assertNull($data['to_district_id']);
        $this->assertNull($data['to_ward_code']);
        $this->assertEqualsCanonicalizing(['to_district_id', 'to_ward_code'], $data['low_confidence_fields']);
    }

    public function test_parse_still_creates_empty_draft_when_ai_fails(): void
    {
        $this->aiResult = null;

        $this->parse($this->connectedCreator())->assertCreated()->assertJson(['data' => [
            'status' => 'draft',
            'to_name' => null,
            'to_phone' => null,
            'to_province_id' => null,
            'cod_amount' => 0,
            'item_name' => 'Hàng hóa dịch vụ',
            'low_confidence_fields' => [],
        ]]);
    }

    public function test_parse_validates_images(): void
    {
        $user = $this->connectedCreator();
        $this->parse($user, 'old', 11)->assertStatus(422)->assertJsonValidationErrors('images');
        $this->actingAs($user)->postJson('/api/v1/creator/shipping-orders/parse', ['address_type' => 'old'])
            ->assertStatus(422)->assertJsonValidationErrors('images');
    }

    public function test_parse_returns_immediately_and_ai_reads_in_background(): void
    {
        Queue::fake();
        $this->aiResult = $this->aiOrder();

        $id = $this->parse($this->connectedCreator())->assertCreated()
            ->assertJsonPath('data.status', 'reading')
            ->assertJsonPath('data.to_name', null)
            ->assertJsonCount(2, 'data.images')
            ->json('data.id');
        $this->assertSame([], $this->aiCalls);
        Queue::assertPushed(ReadShippingOrderImages::class, fn ($job) => $job->orderId === $id);

        // Đang đọc ảnh: chưa sửa / gửi được
        $this->putJson("/api/v1/creator/shipping-orders/{$id}", ['to_name' => 'B'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->postJson("/api/v1/creator/shipping-orders/{$id}/submit", ['payment_type_id' => 2])->assertStatus(409);

        (new ReadShippingOrderImages($id))->handle(app(ShippingOrderService::class));

        $this->getJson("/api/v1/creator/shipping-orders/{$id}")->assertOk()->assertJson(['data' => [
            'status' => 'draft',
            'to_name' => 'Nguyễn Văn A',
            'to_ward_code' => '20101',
        ]]);
        $this->assertCount(2, $this->aiCalls[0]);
    }

    public function test_reading_order_can_be_deleted_and_failed_job_leaves_empty_draft(): void
    {
        Queue::fake();
        $user = $this->connectedCreator();

        $deleted = $this->parse($user)->json('data.id');
        $this->deleteJson("/api/v1/creator/shipping-orders/{$deleted}")->assertNoContent();
        (new ReadShippingOrderImages($deleted))->handle(app(ShippingOrderService::class));
        $this->assertSame([], $this->aiCalls);

        $id = $this->parse($user)->json('data.id');
        (new ReadShippingOrderImages($id))->failed(new \RuntimeException('timeout'));
        $this->getJson("/api/v1/creator/shipping-orders/{$id}")->assertJsonPath('data.status', 'draft');
    }

    // ---- Sửa đơn ----

    public function test_update_resolves_location_names_and_clears_lower_levels(): void
    {
        $this->aiResult = $this->aiOrder();
        $user = $this->connectedCreator();
        $id = $this->parse($user)->json('data.id');

        // Đổi quận -> phường cũ bị xoá để chọn lại; sửa tiền -> bỏ tô vàng
        $this->putJson("/api/v1/creator/shipping-orders/{$id}", ['to_district_id' => 1443, 'cod_amount' => 400000])
            ->assertOk()->assertJson(['data' => [
                'to_district_id' => 1443,
                'to_district_name' => 'Quận 2',
                'to_ward_code' => null,
                'cod_amount' => 400000,
                'low_confidence_fields' => [],
            ]]);

        $this->putJson("/api/v1/creator/shipping-orders/{$id}", ['to_ward_code' => '20102'])
            ->assertOk()->assertJsonPath('data.to_ward_name', 'Phường Bến Thành');

        $this->putJson("/api/v1/creator/shipping-orders/{$id}", ['to_ward_code' => '99999'])
            ->assertStatus(422)->assertJsonValidationErrors('to_ward_code');

        // Đổi sang địa chỉ mới: xoá tỉnh / quận / phường cũ
        $this->putJson("/api/v1/creator/shipping-orders/{$id}", ['address_type' => 'new'])
            ->assertOk()->assertJson(['data' => ['address_type' => 'new', 'to_province_id' => null, 'to_ward_code' => null]]);
    }

    public function test_other_users_orders_are_not_found(): void
    {
        $this->aiResult = $this->aiOrder();
        $id = $this->parse($this->connectedCreator())->json('data.id');

        $other = $this->connectedCreator();
        $this->actingAs($other)->getJson("/api/v1/creator/shipping-orders/{$id}")->assertNotFound();
        $this->getJson('/api/v1/creator/shipping-orders')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- Gửi GHN ----

    public function test_submit_creates_ghn_order_with_names_and_locks_the_order(): void
    {
        $this->aiResult = $this->aiOrder();
        $user = $this->connectedCreator();
        $id = $this->parse($user)->json('data.id');

        $this->postJson("/api/v1/creator/shipping-orders/{$id}/submit", [])
            ->assertStatus(422)->assertJsonValidationErrors('payment_type_id');

        $this->postJson("/api/v1/creator/shipping-orders/{$id}/submit", ['payment_type_id' => 2])
            ->assertOk()->assertJson(['data' => [
                'status' => 'submitted',
                'payment_type_id' => 2,
                'ghn_order_code' => 'GHN123',
                'shipping_fee' => 33000,
            ]]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'shipping-order/create')
            && $r->header('ShopId')[0] === '885'
            && $r['client_order_code'] === "A51-{$id}"
            && $r['is_new_to_address'] === false
            && $r['to_province_name'] === 'Hồ Chí Minh'
            && $r['to_district_name'] === 'Quận 1'
            && $r['to_ward_name'] === 'Phường Bến Nghé'
            && $r['required_note'] === 'CHOXEMHANGKHONGTHU'
            && $r['cod_amount'] === 350000
            && $r['weight'] === 2000
            && $r['content'] === 'Hàng hóa dịch vụ');

        $this->postJson("/api/v1/creator/shipping-orders/{$id}/submit", ['payment_type_id' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->putJson("/api/v1/creator/shipping-orders/{$id}", ['to_name' => 'B'])->assertStatus(409);
        $this->deleteJson("/api/v1/creator/shipping-orders/{$id}")->assertStatus(409);
    }

    public function test_submit_rejects_incomplete_order_without_calling_ghn(): void
    {
        $this->aiResult = null;
        $id = $this->parse($this->connectedCreator())->json('data.id');

        $this->postJson("/api/v1/creator/shipping-orders/{$id}/submit", ['payment_type_id' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to_name', 'to_phone', 'to_address', 'to_province_id', 'to_district_id', 'to_ward_code']);

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), 'shipping-order/create'));
    }

    public function test_ghn_rejection_marks_order_failed_and_can_be_fixed_and_resent(): void
    {
        $this->aiResult = $this->aiOrder(['to_phone' => '0999999999']);
        $id = $this->parse($this->connectedCreator())->json('data.id');

        $this->postJson("/api/v1/creator/shipping-orders/{$id}/submit", ['payment_type_id' => 1])
            ->assertStatus(422)->assertJson(['code' => 'GHN_ERROR', 'message' => 'Số điện thoại không hợp lệ']);
        $this->getJson("/api/v1/creator/shipping-orders/{$id}")
            ->assertJson(['data' => ['status' => 'failed', 'error_message' => 'Số điện thoại không hợp lệ']]);

        $this->putJson("/api/v1/creator/shipping-orders/{$id}", ['to_phone' => '0912345678'])->assertOk();
        $this->postJson("/api/v1/creator/shipping-orders/{$id}/submit", ['payment_type_id' => 1])
            ->assertOk()->assertJson(['data' => ['status' => 'submitted', 'error_message' => null]]);
    }

    public function test_delete_draft_removes_images(): void
    {
        $this->aiResult = $this->aiOrder();
        $id = $this->parse($this->connectedCreator(), 'old', 1)->json('data.id');
        $path = ShippingOrder::query()->findOrFail($id)->images()->value('path');

        $this->deleteJson("/api/v1/creator/shipping-orders/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('shipping_orders', ['id' => $id]);
        $this->assertFalse(\Storage::disk('media')->exists($path));
    }

    // ---- Danh mục ----

    public function test_catalog_endpoints(): void
    {
        $this->actingAs($this->connectedCreator());

        $this->getJson('/api/v1/shipping/ghn/provinces?address_type=old')
            ->assertOk()->assertJsonPath('data.0', ['id' => 202, 'name' => 'Hồ Chí Minh']);
        $this->getJson('/api/v1/shipping/ghn/districts?province_id=202')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/shipping/ghn/wards?district_id=1442')
            ->assertOk()->assertJsonPath('data.0', ['code' => '20101', 'name' => 'Phường Bến Nghé']);
        $this->getJson('/api/v1/shipping/ghn/wards?province_id=1000001&address_type=new')
            ->assertOk()->assertJsonPath('data.0.code', '1003001');
    }

    public function test_normalize_address_names(): void
    {
        $this->assertSame('ho chi minh', GhnCatalog::normalize('Thành phố Hồ Chí Minh'));
        $this->assertSame('ho chi minh', GhnCatalog::normalize('TP. HCM'));
        $this->assertSame('1', GhnCatalog::normalize('Quận 1'));
        $this->assertSame('1', GhnCatalog::normalize('Q1'));
        $this->assertSame('5', GhnCatalog::normalize('Phường 05'));
        $this->assertSame('phu nhuan', GhnCatalog::normalize('Phú Nhuận'));
        $this->assertSame('ben nghe', GhnCatalog::normalize('P. Bến Nghé'));
        $this->assertSame('dong da', GhnCatalog::normalize('Đống Đa'));
    }
}
