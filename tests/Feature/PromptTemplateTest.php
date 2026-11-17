<?php

namespace Tests\Feature;

use App\Models\AiModel;
use Tests\TestCase;

class PromptTemplateTest extends TestCase
{
    private function schema(): array
    {
        return [
            ['key' => 'product_image', 'label' => 'Ảnh sản phẩm', 'type' => 'image', 'required' => true],
            ['key' => 'aspectRatio', 'label' => 'Tỉ lệ khung hình', 'type' => 'aspect_ratio', 'required' => true, 'default_value' => '1:1'],
        ];
    }

    public function test_creative_prompt_is_template_blocks_then_own_prompt(): void
    {
        $user = $this->creator();
        $this->actingAs($user);

        $templateId = $this->postJson('/api/v1/creator/prompt-templates', [
            'title' => 'Studio chung',
            'blocks' => [
                ['title' => 'MỤC TIÊU', 'content' => 'Ảnh sản phẩm studio.'],
                ['title' => '', 'content' => 'Không thêm chữ.'],
                ['title' => '', 'content' => ''],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.prompt', "MỤC TIÊU\nẢnh sản phẩm studio.\n\nKhông thêm chữ.")
            ->json('data.id');

        // Chọn Template thì prompt riêng được bỏ trống
        $emptyId = $this->postJson('/api/v1/creator/creatives', [
            'model_id' => AiModel::query()->firstOrFail()->id,
            'input_schema' => $this->schema(),
            'prompt_template' => '',
            'prompt_template_id' => $templateId,
        ])->assertCreated()->json('data.id');

        $creativeId = $this->postJson('/api/v1/creator/creatives', [
            'model_id' => AiModel::query()->firstOrFail()->id,
            'input_schema' => $this->schema(),
            'prompt_template' => 'Nền đá cẩm thạch.',
            'prompt_template_id' => $templateId,
        ])->assertCreated()
            ->assertJsonPath('data.config.prompt_template', 'Nền đá cẩm thạch.')
            ->assertJsonPath('data.config.prompt_template_id', $templateId)
            ->assertJsonPath('data.config.full_prompt_template', "MỤC TIÊU\nẢnh sản phẩm studio.\n\nKhông thêm chữ.\n\nNền đá cẩm thạch.")
            ->json('data.id');

        // Sửa Template -> prompt gộp của Creative đang dùng đổi theo
        $this->putJson("/api/v1/creator/prompt-templates/{$templateId}", [
            'title' => 'Studio chung',
            'blocks' => [['title' => 'MỤC TIÊU', 'content' => 'Ảnh mới.']],
        ])->assertOk()->assertJsonPath('data.creative_count', 2);
        $this->getJson("/api/v1/creator/creatives/{$creativeId}")->assertOk()
            ->assertJsonPath('data.config.full_prompt_template', "MỤC TIÊU\nẢnh mới.\n\nNền đá cẩm thạch.");
        $this->getJson("/api/v1/creator/creatives/{$emptyId}")->assertOk()
            ->assertJsonPath('data.config.prompt_template', '')
            ->assertJsonPath('data.config.full_prompt_template', "MỤC TIÊU\nẢnh mới.");

        // Template dùng biến mà Creative không có -> không lưu
        $this->putJson("/api/v1/creator/prompt-templates/{$templateId}", [
            'title' => 'Studio chung',
            'blocks' => [['title' => '', 'content' => 'Màu {{mau}}']],
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks']);

        // Đang được dùng thì không xóa được; bỏ chọn rồi mới xóa
        $this->deleteJson("/api/v1/creator/prompt-templates/{$templateId}")->assertStatus(409);
        foreach ([$creativeId, $emptyId] as $id) {
            $this->putJson("/api/v1/creator/creatives/{$id}", [
                'input_schema' => $this->schema(),
                'prompt_template' => 'Riêng',
                'prompt_template_id' => null,
            ])->assertOk()->assertJsonPath('data.config.full_prompt_template', 'Riêng');
        }
        $this->deleteJson("/api/v1/creator/prompt-templates/{$templateId}")->assertNoContent();
    }

    public function test_pinned_block_goes_after_creative_prompt(): void
    {
        $this->actingAs($this->creator());

        $templateId = $this->postJson('/api/v1/creator/prompt-templates', [
            'title' => 'Có ghim',
            'blocks' => [
                ['title' => 'ĐẦU RA', 'content' => 'Không watermark.', 'pinned' => true],
                ['title' => 'MỤC TIÊU', 'content' => 'Ảnh studio.'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.prompt', "MỤC TIÊU\nẢnh studio.")
            ->assertJsonPath('data.pinned_prompt', "ĐẦU RA\nKhông watermark.")
            ->assertJsonPath('data.blocks.0.pinned', true)
            ->json('data.id');

        $this->postJson('/api/v1/creator/creatives', [
            'model_id' => AiModel::query()->firstOrFail()->id,
            'input_schema' => $this->schema(),
            'prompt_template' => 'Nền gỗ.',
            'prompt_template_id' => $templateId,
        ])->assertCreated()
            ->assertJsonPath('data.config.full_prompt_template', "MỤC TIÊU\nẢnh studio.\n\nNền gỗ.\n\nĐẦU RA\nKhông watermark.");

        // Chỉ ghim được 1 block
        $this->putJson("/api/v1/creator/prompt-templates/{$templateId}", [
            'title' => 'Có ghim',
            'blocks' => [
                ['title' => 'A', 'content' => 'a', 'pinned' => true],
                ['title' => 'B', 'content' => 'b', 'pinned' => true],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks']);
    }

    public function test_creative_without_template_still_requires_prompt_and_templates_are_private(): void
    {
        $owner = $this->creator();
        $other = $this->creator();

        $templateId = $this->actingAs($owner)->postJson('/api/v1/creator/prompt-templates', [
            'title' => 'Của tôi', 'blocks' => [['title' => 'A', 'content' => 'B']],
        ])->assertCreated()->json('data.id');

        $this->actingAs($other)->getJson("/api/v1/creator/prompt-templates/{$templateId}")->assertNotFound();
        $this->getJson('/api/v1/creator/prompt-templates')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/creator/creatives', [
            'model_id' => AiModel::query()->firstOrFail()->id,
            'input_schema' => $this->schema(),
            'prompt_template' => 'x',
            'prompt_template_id' => $templateId,
        ])->assertStatus(422)->assertJsonValidationErrors(['prompt_template_id']);

        $this->postJson('/api/v1/creator/creatives', [
            'model_id' => AiModel::query()->firstOrFail()->id,
            'input_schema' => $this->schema(),
            'prompt_template' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['prompt_template']);

        $this->postJson('/api/v1/creator/prompt-templates', [
            'title' => 'Rỗng', 'blocks' => [['title' => '', 'content' => '']],
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks']);
    }
}
