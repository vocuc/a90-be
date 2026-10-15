<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Category;
use App\Models\Generation;
use App\Services\SettingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Creative làm bằng Template Builder: form người mua theo input_schema, prompt theo prompt_template.
 */
class CreativeTemplateTest extends TestCase
{
    private function schema(array $override = []): array
    {
        return [
            ['key' => 'product_image', 'label' => 'Ảnh giày', 'type' => 'image', 'required' => true,
                'validation' => ['max_size_mb' => 10, 'allowed_types' => ['image/jpeg', 'image/png', 'image/webp']]],
            ['key' => 'outfit_image', 'label' => 'Ảnh outfit', 'type' => 'image', 'required' => true,
                'validation' => ['max_size_mb' => 10, 'allowed_types' => ['image/jpeg', 'image/png', 'image/webp']]],
            ['key' => 'aspectRatio', 'label' => 'Tỉ lệ khung hình', 'type' => 'aspect_ratio', 'required' => true,
                'default_value' => '9:16', 'options' => [['value' => '9:16', 'label' => '9:16']]],
            ...$override,
        ];
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'title' => 'Người mẫu đi giày',
            'model_id' => AiModel::query()->where('code', 'gemini-3-pro-image')->value('id'),
            'price_per_image' => 2000,
            'description' => 'Chụp giày trên nền trơn.',
            'category_id' => Category::query()->value('id'),
            'video_url' => 'https://www.tiktok.com/@shop/video/123',
            'input_schema' => $this->schema(),
            'prompt_template' => 'Tạo một người mẫu mặc outfitImage và đi đôi giày của tôi ở productImage',
        ], $override);
    }

    /** Creator tạo Creative template, đăng bán; trả slug. */
    private function publishTemplateCreative(): array
    {
        $creator = $this->creator();
        $this->actingAs($creator);

        $id = $this->postJson('/api/v1/creator/creatives', $this->payload())->assertCreated()->json('data.id');
        $this->post("/api/v1/creator/creatives/{$id}/images", ['image' => UploadedFile::fake()->image('cover.jpg')],
            ['Accept' => 'application/json'])->assertCreated();
        $slug = $this->postJson("/api/v1/creator/creatives/{$id}/publish")->assertOk()->json('data.slug');

        return [$id, $slug];
    }

    public function test_creator_saves_template_and_buyer_sees_form_without_prompt(): void
    {
        [$id, $slug] = $this->publishTemplateCreative();

        $this->getJson("/api/v1/creator/creatives/{$id}")->assertOk()
            ->assertJsonPath('data.description', 'Chụp giày trên nền trơn.')
            ->assertJsonPath('data.category_id', Category::query()->value('id'))
            ->assertJsonPath('data.video_url', 'https://www.tiktok.com/@shop/video/123')
            ->assertJsonPath('data.config.input_schema.2.key', 'aspectRatio')
            ->assertJsonPath('data.config.default_aspect_ratio', '9:16')
            ->assertJsonPath('data.config.prompt_template', $this->payload()['prompt_template']);

        $this->app['auth']->forgetGuards();
        $detail = $this->getJson("/api/v1/creatives/{$slug}")->assertOk()
            ->assertJsonPath('data.input_schema.0.key', 'product_image')
            ->assertJsonPath('data.description', 'Chụp giày trên nền trơn.')
            ->assertJsonPath('data.options.default_aspect_ratio', '9:16');
        $this->assertStringNotContainsString('Tạo một người mẫu', $detail->getContent());
    }

    public function test_invalid_templates_are_rejected(): void
    {
        $this->actingAs($this->creator());
        $post = fn (array $override) => $this->postJson('/api/v1/creator/creatives', $this->payload($override));

        // Thiếu trường tỉ lệ khung hình
        $post(['input_schema' => array_slice($this->schema(), 0, 2)])->assertStatus(422)->assertJsonValidationErrors('input_schema');
        // Tên trường tỉ lệ phải là aspectRatio
        $schema = $this->schema();
        $schema[2]['key'] = 'ratio';
        $post(['input_schema' => $schema])->assertStatus(422)->assertJsonValidationErrors('input_schema.2.key');
        // Tên trường trùng
        $schema = $this->schema();
        $schema[1]['key'] = 'product_image';
        $post(['input_schema' => $schema])->assertStatus(422)->assertJsonValidationErrors('input_schema.1.key');
        // Biến chưa khai báo / biến là trường ảnh
        $post(['prompt_template' => 'Ảnh {{missing}}'])->assertStatus(422)->assertJsonValidationErrors('prompt_template');
        $post(['prompt_template' => 'Ảnh {{product_image}}'])->assertStatus(422)->assertJsonValidationErrors('prompt_template');
        // Loại trường chưa hỗ trợ
        $post(['input_schema' => $this->schema([['key' => 'gallery', 'label' => 'Bộ ảnh', 'type' => 'image_set', 'required' => true]])])
            ->assertStatus(422)->assertJsonValidationErrors('input_schema.3.type');
        // Video không phải TikTok
        $post(['video_url' => 'https://youtube.com/watch?v=1'])->assertStatus(422)->assertJsonValidationErrors('video_url');
        $post(['video_url' => 'https://faketiktok.com/x'])->assertStatus(422)->assertJsonValidationErrors('video_url');
    }

    public function test_buyer_generates_with_template_inputs_and_worker_gets_flat_request(): void
    {
        [, $slug] = $this->publishTemplateCreative();
        $creativeId = $this->getJson("/api/v1/creatives/{$slug}")->json('data.id');
        $buyer = $this->buyer(credit: 5000);

        $send = fn (array $inputs) => $this->actingAs($buyer)->post('/api/v1/generations', [
            'creative_id' => $creativeId,
            'quantity' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'inputs' => $inputs,
        ], ['Accept' => 'application/json']);

        // Thiếu ảnh bắt buộc
        $send(['product_image' => UploadedFile::fake()->image('shoe.png')])
            ->assertStatus(422)->assertJsonValidationErrors('inputs.outfit_image');

        $response = $send([
            'product_image' => UploadedFile::fake()->image('shoe.png'),
            'outfit_image' => UploadedFile::fake()->image('outfit.jpg'),
            'aspectRatio' => '16:9',
        ])->assertStatus(202)->assertJsonPath('data.aspect_ratio', '16:9');

        $generation = Generation::query()->findOrFail($response->json('data.id'));
        $this->assertEqualsCanonicalizing(['product_image', 'outfit_image'], array_keys($generation->input_paths));

        $job = $this->claimJob($this->workerToken())->assertOk()->json('data');
        $this->assertSame([
            'productImage' => "https://media.test/{$generation->input_paths['product_image']}",
            'outfitImage' => "https://media.test/{$generation->input_paths['outfit_image']}",
            'prompt' => 'Tạo một người mẫu mặc outfitImage và đi đôi giày của tôi ở productImage',
            'aspectRatio' => '16:9',
            'modelDisplayName' => 'Nano Banana Pro (Gemini 3 Pro Image)',
        ], $job['request']);
    }

    public function test_aspect_ratio_defaults_to_creator_choice_and_text_variables_fill_prompt(): void
    {
        $this->actingAs($creator = $this->creator());
        $id = $this->postJson('/api/v1/creator/creatives', $this->payload([
            'input_schema' => $this->schema([['key' => 'note', 'label' => 'Ghi chú', 'type' => 'text', 'required' => false]]),
            'prompt_template' => "Giày đẹp.\n{{note}}",
        ]))->assertCreated()->json('data.id');

        $response = $this->post("/api/v1/creator/creatives/{$id}/test", [
            'quantity' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'inputs' => [
                'product_image' => UploadedFile::fake()->image('shoe.png'),
                'outfit_image' => UploadedFile::fake()->image('outfit.png'),
                'note' => 'Nền màu be',
            ],
        ], ['Accept' => 'application/json'])->assertStatus(202)->assertJsonPath('data.aspect_ratio', '9:16');

        $generation = Generation::query()->findOrFail($response->json('data.id'));
        $this->assertSame("Giày đẹp.\nNền màu be", $generation->prompt_snapshot);
        $this->assertEquals(['note' => 'Nền màu be', 'aspectRatio' => '9:16'], $generation->input_values);
    }

    private function uploadAsset(string $name = 'logo.png'): int
    {
        return $this->post('/api/v1/creator/creative-assets', ['image' => UploadedFile::fake()->image($name)],
            ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    private function hiddenField(string $key, int $assetId): array
    {
        return ['key' => $key, 'label' => 'Ảnh '.$key, 'type' => 'hidden_image', 'asset_id' => $assetId];
    }

    public function test_hidden_images_are_sent_to_worker_but_not_shown_to_buyer(): void
    {
        $this->actingAs($creator = $this->creator());
        $this->getJson('/api/v1/creator/template-limits')->assertOk()->assertJsonPath('data.max_hidden_images', 5);

        $logo = $this->uploadAsset();
        $id = $this->postJson('/api/v1/creator/creatives', $this->payload([
            'input_schema' => $this->schema([$this->hiddenField('brand_logo', $logo)]),
        ]))->assertCreated()
            ->assertJsonPath('data.config.input_schema.3.type', 'hidden_image')
            ->assertJsonPath('data.config.input_schema.3.asset_id', $logo)
            ->json('data.id');
        $this->assertNotNull($this->getJson("/api/v1/creator/creatives/{$id}")->json('data.config.input_schema.3.asset_url'));

        $this->post("/api/v1/creator/creatives/{$id}/images", ['image' => UploadedFile::fake()->image('cover.jpg')],
            ['Accept' => 'application/json'])->assertCreated();
        $slug = $this->postJson("/api/v1/creator/creatives/{$id}/publish")->assertOk()->json('data.slug');

        // Người mua không thấy trường ẩn và không gửi được giá trị cho nó
        $this->app['auth']->forgetGuards();
        $detail = $this->getJson("/api/v1/creatives/{$slug}")->assertOk();
        $this->assertCount(3, $detail->json('data.input_schema'));
        $this->assertStringNotContainsString('brand_logo', $detail->getContent());

        $buyer = $this->buyer(credit: 5000);
        $response = $this->actingAs($buyer)->post('/api/v1/generations', [
            'creative_id' => $id,
            'quantity' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'inputs' => [
                'product_image' => UploadedFile::fake()->image('shoe.png'),
                'outfit_image' => UploadedFile::fake()->image('outfit.jpg'),
                'brand_logo' => 'x',
            ],
        ], ['Accept' => 'application/json'])->assertStatus(202);
        $this->assertArrayNotHasKey('brand_logo', $response->json('data.input_values') ?? []);
        $this->assertCount(2, $response->json('data.input_images'));

        $generation = Generation::query()->findOrFail($response->json('data.id'));
        $this->assertSame(['brand_logo'], array_keys($generation->asset_paths));
        $this->assertArrayNotHasKey('brand_logo', $generation->input_values);

        $job = $this->claimJob($this->workerToken())->assertOk()->json('data.request');
        $this->assertSame(['productImage', 'outfitImage', 'brandLogo', 'prompt', 'aspectRatio', 'modelDisplayName'], array_keys($job));
        $this->assertSame("https://media.test/{$generation->asset_paths['brand_logo']}", $job['brandLogo']);
    }

    public function test_hidden_images_are_limited_and_must_belong_to_creator(): void
    {
        $this->actingAs($this->creator());
        $foreign = $this->uploadAsset();

        $this->actingAs($this->creator());
        $post = fn (array $fields) => $this->postJson('/api/v1/creator/creatives', $this->payload(['input_schema' => $this->schema($fields)]));

        // Ảnh của Creator khác / chưa tải ảnh
        $post([$this->hiddenField('logo', $foreign)])->assertStatus(422)->assertJsonValidationErrors('input_schema.3.asset_id');
        $post([['key' => 'logo', 'label' => 'Logo', 'type' => 'hidden_image']])
            ->assertStatus(422)->assertJsonValidationErrors('input_schema.3.asset_id');
        // Biến ảnh ẩn không chèn vào prompt như chữ
        $logo = $this->uploadAsset();
        $this->postJson('/api/v1/creator/creatives', $this->payload([
            'input_schema' => $this->schema([$this->hiddenField('logo', $logo)]),
            'prompt_template' => 'Gắn {{logo}}',
        ]))->assertStatus(422)->assertJsonValidationErrors('prompt_template');

        // Vượt giới hạn admin cấu hình
        app(SettingService::class)->set('generation.max_hidden_images', 1);
        $post([$this->hiddenField('logo', $logo), $this->hiddenField('logo_2', $this->uploadAsset())])
            ->assertStatus(422)->assertJsonValidationErrors('input_schema');
        $post([$this->hiddenField('logo', $logo)])->assertCreated();

        // Ảnh đã gắn vào Creative này thì Creative khác không dùng được
        $post([$this->hiddenField('logo', $logo)])->assertStatus(422)->assertJsonValidationErrors('input_schema.3.asset_id');
    }
}
