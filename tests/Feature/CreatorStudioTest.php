<?php

namespace Tests\Feature;

use App\Models\AiModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreatorStudioTest extends TestCase
{
    public function test_creator_flow_from_profile_to_publish_and_marketplace(): void
    {
        $user = $this->buyer();
        $this->actingAs($user);

        $this->postJson('/api/v1/creator/creatives', [])->assertStatus(403)->assertJsonPath('code', 'CREATOR_PROFILE_REQUIRED');

        $this->postJson('/api/v1/creator/profile', ['shop_name' => 'Shop A', 'username' => 'shop-a'])->assertCreated();

        $creativeId = $this->postJson('/api/v1/creator/creatives', [
            'title' => 'Ảnh mỹ phẩm',
            'model_id' => AiModel::query()->firstOrFail()->id,
            'price_per_image' => 2000,
            'max_outputs' => 4,
            'input_schema' => [
                ['key' => 'product_image', 'label' => 'Ảnh mỹ phẩm', 'type' => 'image', 'required' => true],
                ['key' => 'aspectRatio', 'label' => 'Tỉ lệ khung hình', 'type' => 'aspect_ratio', 'required' => true, 'default_value' => '4:5'],
            ],
            'prompt_template' => 'Luxury cosmetic shot',
            'is_public' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.is_public', true)->json('data.id');

        $this->getJson("/api/v1/creator/creatives/{$creativeId}")->assertOk()
            ->assertJsonPath('data.config.prompt_template', 'Luxury cosmetic shot')
            ->assertJsonPath('data.config.default_aspect_ratio', '4:5');

        // Tạo mới bắt buộc có form người mua và prompt
        $this->postJson('/api/v1/creator/creatives', [
            'title' => 'Thiếu template', 'model_id' => AiModel::query()->firstOrFail()->id, 'price_per_image' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['input_schema', 'prompt_template']);

        // Chưa có ảnh mẫu: chưa đăng được.
        $this->postJson("/api/v1/creator/creatives/{$creativeId}/publish")
            ->assertStatus(422)->assertJsonPath('code', 'CREATIVE_NOT_PUBLISHABLE');

        $this->post("/api/v1/creator/creatives/{$creativeId}/images", [
            'image' => UploadedFile::fake()->image('sample.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $slug = $this->postJson("/api/v1/creator/creatives/{$creativeId}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published')->json('data.slug');

        // Marketplace công khai: không cần đăng nhập, không lộ prompt.
        $this->app['auth']->forgetGuards();
        $detail = $this->getJson("/api/v1/creatives/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.price_per_image', 2000)
            ->assertJsonPath('data.options.max_outputs', 4)
            ->assertJsonPath('data.options.aspect_ratios', AiModel::query()->firstOrFail()->supported_aspect_ratios)
            ->assertJsonPath('data.options.default_aspect_ratio', '4:5')
            ->assertJsonPath('data.creator.username', 'shop-a');
        $this->assertStringNotContainsString('Luxury cosmetic shot', $detail->getContent());

        $this->getJson('/api/v1/creatives')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/creators/shop-a')->assertOk()
            ->assertJsonPath('data.profile.creative_count', 1)
            ->assertJsonPath('data.profile.shop_name', 'Shop A');
        $this->assertNotNull($this->getJson('/api/v1/creators/shop-a')->json('data.profile.created_at'));
        $this->getJson('/api/v1/creators/khong-co')->assertNotFound();

        // Lọc Creative theo username của Creator
        $this->getJson('/api/v1/creatives?creator=shop-a&sort=popular')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $slug);
        $this->getJson('/api/v1/creatives?creator=khong-co')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_private_creative_is_hidden_from_marketplace_but_usable_by_owner(): void
    {
        $creator = $this->creator(credit: 10000);
        $creative = $this->publishedCreative($creator);
        $creative->update(['is_public' => false]);

        // Tạo mới không gửi is_public -> private
        $this->actingAs($creator)->postJson('/api/v1/creator/creatives', [
            'title' => 'Mẫu riêng',
            'model_id' => AiModel::query()->firstOrFail()->id,
            'price_per_image' => 0,
            'input_schema' => [
                ['key' => 'product_image', 'label' => 'Ảnh', 'type' => 'image', 'required' => true],
                ['key' => 'aspectRatio', 'label' => 'Tỉ lệ khung hình', 'type' => 'aspect_ratio', 'required' => true, 'default_value' => '1:1'],
            ],
            'prompt_template' => 'Studio shot',
        ])->assertCreated()->assertJsonPath('data.is_public', false);

        // Chủ sở hữu vẫn mở và tạo ảnh được
        $this->getJson("/api/v1/creatives/{$creative->slug}")->assertOk();
        $this->generate($creator, $creative)->assertStatus(202);

        // Người khác không thấy, không tạo ảnh được
        $buyer = $this->buyer(10000);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/creatives')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/creatives/{$creative->slug}")->assertNotFound();
        $this->generate($buyer, $creative)->assertNotFound()->assertJsonPath('code', 'CREATIVE_NOT_AVAILABLE');

        // Bật public -> hiện trên Marketplace và tính vào số Creative của cửa hàng
        $this->actingAs($creator)->putJson("/api/v1/creator/creatives/{$creative->id}", ['is_public' => true])
            ->assertOk()->assertJsonPath('data.is_public', true);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/creatives/{$creative->slug}")->assertOk();
        $this->assertSame(1, $creator->creatorProfile()->first()->creative_count);
    }

    public function test_creator_can_search_and_filter_own_creatives_by_visibility(): void
    {
        $creator = $this->creator();
        $private = $this->publishedCreative($creator);
        $private->update(['title' => 'Áo thun riêng', 'is_public' => false]);
        $public = $this->publishedCreative($creator);
        $public->update(['title' => 'Cốc sứ', 'is_public' => true]);
        $this->publishedCreative($this->creator())->update(['title' => 'Áo thun người khác', 'is_public' => false]);

        $this->actingAs($creator)->getJson('/api/v1/creator/creatives?visibility=private')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $private->id);
        $this->getJson('/api/v1/creator/creatives?visibility=public')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $public->id);
        $this->getJson('/api/v1/creator/creatives?q=thun')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $private->id);
        $this->getJson('/api/v1/creator/creatives?q='.urlencode('100%'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/creator/creatives?visibility=hidden')->assertStatus(422)->assertJsonValidationErrors('visibility');
    }

    public function test_api_key_endpoints_no_longer_exist(): void
    {
        $this->actingAs($this->creator())->getJson('/api/v1/creator/api-keys')->assertNotFound();
    }

    public function test_creator_test_run_goes_through_worker_without_charging(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 5000);
        $creative->update(['status' => 'draft']);

        $response = $this->actingAs($creator)->post("/api/v1/creator/creatives/{$creative->id}/test", [
            'inputs' => ['product_image' => UploadedFile::fake()->image('p.png'), 'aspectRatio' => '1:1'],
            'quantity' => 1,
            'idempotency_key' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', 'test');

        $this->assertSame('queued', $this->latestStatus($response)['status']);
        $this->runWorker([null]);
        $this->assertSame('completed', $this->latestStatus($response)['status']);

        $this->assertSame(0, $this->balance($creator));
    }

    public function test_other_creators_cannot_touch_my_creative(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $other = $this->creator();

        $this->actingAs($other)->getJson("/api/v1/creator/creatives/{$creative->id}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/creator/creatives/{$creative->id}/pause")->assertNotFound();
    }

    public function test_creative_with_generations_cannot_be_hard_deleted(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator);
        $this->generate($this->buyer(credit: 5000), $creative);
        $creative->update(['status' => 'draft']);

        $this->actingAs($creator)->deleteJson("/api/v1/creator/creatives/{$creative->id}")
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_archived_status_no_longer_exists(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator);

        $this->actingAs($creator)->postJson("/api/v1/creator/creatives/{$creative->id}/archive")->assertNotFound();
        $this->actingAs($creator)->getJson('/api/v1/creator/creatives?status=archived')
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }
}
