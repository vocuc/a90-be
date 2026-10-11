<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Category;
use App\Models\Generation;
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
}
