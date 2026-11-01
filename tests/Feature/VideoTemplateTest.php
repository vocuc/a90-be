<?php

namespace Tests\Feature;

use App\Models\VideoTemplate;
use Tests\TestCase;

/** Quản lý video template (note/quan-ly-video-template.txt ở repo frontend). */
class VideoTemplateTest extends TestCase
{
    private const URL = '/api/v1/creator/video-templates';

    private const VIDEO = 'https://www.tiktok.com/@a51.vn/video/7400000000000000000';

    public function test_creator_creates_lists_updates_and_deletes(): void
    {
        $owner = $this->creator();

        $id = $this->actingAs($owner)->postJson(self::URL, ['name' => '  Mở hộp giày  ', 'video_url' => self::VIDEO])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Mở hộp giày')
            ->assertJsonPath('data.video_url', self::VIDEO)
            ->json('data.id');
        $this->postJson(self::URL, ['name' => 'Mẫu 2', 'video_url' => 'https://vt.tiktok.com/ZSabc123/'])->assertCreated();

        // Mới tạo trước
        $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Mẫu 2')
            ->assertJsonPath('data.1.id', $id)
            ->assertJsonStructure(['data' => [['id', 'name', 'video_url', 'created_at']], 'meta']);

        $this->putJson(self::URL."/{$id}", ['name' => 'Đổi tên', 'video_url' => 'https://vm.tiktok.com/ZMxyz/'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Đổi tên');
        $this->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.video_url', 'https://vm.tiktok.com/ZMxyz/');

        $this->deleteJson(self::URL."/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('video_templates', ['id' => $id]);
    }

    public function test_validation(): void
    {
        $this->actingAs($this->creator());

        $this->postJson(self::URL, [])->assertStatus(422)->assertJsonValidationErrors(['name', 'video_url']);
        foreach (['https://youtube.com/watch?v=1', 'https://faketiktok.com/a', 'tiktok.com/@a/video/1', 'https://tiktok.com.evil.vn/x'] as $url) {
            $this->postJson(self::URL, ['name' => 'A', 'video_url' => $url])->assertStatus(422)->assertJsonValidationErrors('video_url');
        }
        $this->postJson(self::URL, ['name' => str_repeat('a', 151), 'video_url' => self::VIDEO])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(0, VideoTemplate::query()->count());
    }

    public function test_only_own_templates_and_requires_shop(): void
    {
        $owner = $this->creator();
        $template = VideoTemplate::query()->create(['user_id' => $owner->id, 'name' => 'A', 'video_url' => self::VIDEO]);

        $other = $this->creator();
        $this->actingAs($other)->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL."/{$template->id}")->assertNotFound();
        $this->putJson(self::URL."/{$template->id}", ['name' => 'B', 'video_url' => self::VIDEO])->assertNotFound();
        $this->deleteJson(self::URL."/{$template->id}")->assertNotFound();
        $this->assertDatabaseHas('video_templates', ['id' => $template->id, 'name' => 'A']);

        // Chưa mở cửa hàng
        $this->actingAs($this->buyer())->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, ['name' => 'A', 'video_url' => self::VIDEO])->assertForbidden();
    }
}
