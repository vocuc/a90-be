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
            'creative_prompt' => [['label' => 'Phong cách', 'value' => 'Luxury cosmetic shot']],
            'max_outputs' => 4,
            'allowed_aspect_ratios' => ['1:1', '4:5'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        // creative_prompt lưu và trả về dạng mảng, không phải chuỗi.
        $this->getJson("/api/v1/creator/creatives/{$creativeId}")->assertOk()
            ->assertJsonPath('data.config.creative_prompt', [['label' => 'Phong cách', 'value' => 'Luxury cosmetic shot']]);

        // Chuỗi thường và câu lệnh thiếu nội dung bị từ chối.
        $this->putJson("/api/v1/creator/creatives/{$creativeId}", ['creative_prompt' => 'Luxury cosmetic shot'])
            ->assertStatus(422)->assertJsonValidationErrors('creative_prompt');
        $this->putJson("/api/v1/creator/creatives/{$creativeId}", ['creative_prompt' => [['label' => 'Phong cách']]])
            ->assertStatus(422)->assertJsonValidationErrors('creative_prompt.0.value');

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
            ->assertJsonPath('data.options.aspect_ratios', ['1:1', '4:5'])
            ->assertJsonPath('data.creator.username', 'shop-a');
        $this->assertStringNotContainsString('Luxury cosmetic shot', $detail->getContent());

        $this->getJson('/api/v1/creatives')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/creators/shop-a')->assertOk()->assertJsonPath('data.profile.creative_count', 1);
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
            'input_image' => UploadedFile::fake()->image('p.png'),
            'quantity' => 1,
            'aspect_ratio' => '1:1',
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
