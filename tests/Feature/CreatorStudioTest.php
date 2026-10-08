<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\CreatorApiKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        // Thêm key: hệ thống gọi Gemini để kiểm tra.
        Http::fake(['*/models?*' => Http::response(['models' => []])]);
        $keyResponse = $this->postJson('/api/v1/creator/api-keys', [
            'provider_id' => AiModel::query()->firstOrFail()->provider_id,
            'label' => 'Key chính',
            'api_key' => 'AIzaSySECRETKEY1234',
        ])->assertCreated();
        $this->assertStringNotContainsString('SECRETKEY', $keyResponse->getContent());
        $this->assertSame('••••1234', $keyResponse->json('data.key_hint'));
        // Key được mã hóa trong DB.
        $this->assertStringNotContainsString('SECRETKEY', DB::table('creator_api_keys')->value('api_key'));
        $this->assertSame('AIzaSySECRETKEY1234', CreatorApiKey::query()->firstOrFail()->api_key);

        $creativeId = $this->postJson('/api/v1/creator/creatives', [
            'title' => 'Ảnh mỹ phẩm',
            'model_id' => AiModel::query()->firstOrFail()->id,
            'price_per_image' => 2000,
            'creative_prompt' => 'Luxury cosmetic shot',
            'max_outputs' => 4,
            'allowed_aspect_ratios' => ['1:1', '4:5'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

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

    public function test_invalid_api_key_is_rejected_on_save(): void
    {
        $user = $this->creator(keys: 0);
        Http::fake(['*/models?*' => Http::response(self::geminiInvalidKey(), 400)]);

        $this->actingAs($user)->postJson('/api/v1/creator/api-keys', [
            'provider_id' => AiModel::query()->firstOrFail()->provider_id,
            'label' => 'Sai',
            'api_key' => 'AIzaWRONGKEY0000',
        ])->assertStatus(422)->assertJsonPath('code', 'API_KEY_INVALID');

        $this->assertSame(0, CreatorApiKey::query()->count());
    }

    public function test_creator_test_run_uses_own_key_without_charging(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 5000);
        $creative->update(['status' => 'draft']);
        $this->fakeGemini([Http::response(self::geminiImage())]);

        $response = $this->actingAs($creator)->post("/api/v1/creator/creatives/{$creative->id}/test", [
            'input_image' => UploadedFile::fake()->image('p.png'),
            'quantity' => 1,
            'aspect_ratio' => '1:1',
            'idempotency_key' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', 'test');

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
        $this->fakeGemini([Http::response(self::geminiImage())]);
        $this->generate($this->buyer(credit: 5000), $creative);
        $creative->update(['status' => 'draft']);

        $this->actingAs($creator)->deleteJson("/api/v1/creator/creatives/{$creative->id}")
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }
}
