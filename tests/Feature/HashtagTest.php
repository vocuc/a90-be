<?php

namespace Tests\Feature;

use App\Enums\CreativeStatus;
use App\Models\AiModel;
use App\Models\Hashtag;
use Tests\TestCase;

class HashtagTest extends TestCase
{
    private function creativePayload(array $overrides = []): array
    {
        return [
            'title' => 'Ảnh chân dung',
            'model_id' => AiModel::query()->firstOrFail()->id,
            'price_per_image' => 1000,
            'input_schema' => [
                ['key' => 'photo', 'label' => 'Ảnh', 'type' => 'image', 'required' => true],
                ['key' => 'aspectRatio', 'label' => 'Tỉ lệ khung hình', 'type' => 'aspect_ratio', 'required' => true, 'default_value' => '1:1'],
            ],
            'prompt_template' => 'Portrait',
            ...$overrides,
        ];
    }

    public function test_creator_sets_hashtags_with_dedupe_and_order(): void
    {
        $this->actingAs($this->creator());

        $id = $this->postJson('/api/v1/creator/creatives', $this->creativePayload([
            'hashtags' => ['#ChânDung', 'anime', 'chandung', ' #Tết_2026 '],
        ]))->assertCreated()
            ->assertJsonCount(3, 'data.hashtags')
            ->assertJsonPath('data.hashtags.0', ['name' => 'ChânDung', 'slug' => 'chandung'])
            ->assertJsonPath('data.hashtags.1.slug', 'anime')
            ->assertJsonPath('data.hashtags.2', ['name' => 'Tết_2026', 'slug' => 'tet_2026'])
            ->json('data.id');

        // Sửa không gửi hashtags: giữ nguyên
        $this->putJson("/api/v1/creator/creatives/{$id}", ['title' => 'Đổi tên'])
            ->assertOk()->assertJsonCount(3, 'data.hashtags');

        // Gửi danh sách mới: thay thế, theo thứ tự mới; hashtag đã có giữ tên hiển thị cũ
        $this->putJson("/api/v1/creator/creatives/{$id}", ['hashtags' => ['anime', 'CHANDUNG']])
            ->assertOk()
            ->assertJsonCount(2, 'data.hashtags')
            ->assertJsonPath('data.hashtags.0.slug', 'anime')
            ->assertJsonPath('data.hashtags.1.name', 'ChânDung');

        $this->putJson("/api/v1/creator/creatives/{$id}", ['hashtags' => []])
            ->assertOk()->assertJsonCount(0, 'data.hashtags');
        $this->assertSame(3, Hashtag::query()->count());

        $this->getJson('/api/v1/creator/creatives')->assertOk()->assertJsonCount(0, 'data.0.hashtags');
    }

    public function test_invalid_hashtags_are_rejected(): void
    {
        $this->actingAs($this->creator());

        $this->postJson('/api/v1/creator/creatives', $this->creativePayload([
            'hashtags' => ['chân dung', 'a-b', '#', str_repeat('a', 51)],
        ]))->assertStatus(422)->assertJsonValidationErrors(['hashtags.0', 'hashtags.1', 'hashtags.2', 'hashtags.3']);

        $this->postJson('/api/v1/creator/creatives', $this->creativePayload([
            'hashtags' => array_map(fn ($i) => "tag{$i}", range(1, 11)),
        ]))->assertStatus(422)->assertJsonValidationErrors(['hashtags']);
    }

    public function test_marketplace_shows_filters_and_suggests_hashtags(): void
    {
        $creator = $this->creator();
        $a = $this->publishedCreative($creator);
        $b = $this->publishedCreative($creator);
        $draft = $this->publishedCreative($creator);
        $draft->update(['status' => CreativeStatus::Draft]);

        $tag = fn (string $name) => Hashtag::query()->create(['name' => $name, 'slug' => Hashtag::slugFor($name)]);
        $chanDung = $tag('ChânDung');
        $chanDungNam = $tag('ChanDungNam');
        $anime = $tag('Anime');
        $nhap = $tag('ChiNhap');
        $a->hashtags()->attach([$chanDung->id => ['sort_order' => 0], $anime->id => ['sort_order' => 1]]);
        $b->hashtags()->attach([$chanDung->id, $chanDungNam->id]);
        $draft->hashtags()->attach([$nhap->id, $anime->id]);

        $this->getJson("/api/v1/creatives/{$a->slug}")->assertOk()
            ->assertJsonPath('data.hashtags', [['name' => 'ChânDung', 'slug' => 'chandung'], ['name' => 'Anime', 'slug' => 'anime']]);

        // Lọc theo slug hoặc tên (có #, có dấu)
        $this->getJson('/api/v1/creatives?hashtag=chandung')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/creatives?hashtag=%23Anime')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $a->slug);
        $this->getJson('/api/v1/creatives?hashtag=chinhap')->assertOk()->assertJsonCount(0, 'data');

        // Gợi ý: chỉ đếm Creative đang bán, nhiều nhất trước, lọc theo tiền tố
        $this->getJson('/api/v1/hashtags')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0', ['name' => 'ChânDung', 'slug' => 'chandung', 'creative_count' => 2])
            ->assertJsonMissing(['slug' => 'chinhap']);
        $this->getJson('/api/v1/hashtags?q=%23Chân')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.slug', 'chandungnam');
        $this->getJson('/api/v1/hashtags?q=chan_')->assertOk()->assertJsonCount(0, 'data');
    }
}
