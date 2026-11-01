<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoTemplate;
use App\Models\Worker;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Máy làm template (D:\video-tmp) đồng bộ template vào shop worker được gắn: PUT /api/v1/video-templates/sync. */
class VideoTemplateSyncTest extends TestCase
{
    private function token(?User $shop): string
    {
        [$worker, $token] = Worker::register('lam-template-'.Str::random(5));
        $worker->update(['video_user_id' => $shop?->id]);

        return $token;
    }

    private function sync(string $token, array $templates): TestResponse
    {
        return $this->withToken($token)->putJson('/api/v1/video-templates/sync', ['templates' => $templates]);
    }

    public function test_sync_creates_updates_hides_and_restores(): void
    {
        $shop = $this->creator();
        $own = VideoTemplate::query()->create([
            'user_id' => $shop->id, 'name' => 'Shop tự tạo', 'video_url' => 'https://www.tiktok.com/@a/video/1', 'image_count' => 2,
        ]);
        $token = $this->token($shop);

        $this->sync($token, [
            ['key' => '1', 'name' => 'StripReveal', 'image_count' => 2],
            ['key' => '5', 'name' => 'FlashMontage', 'image_count' => 12],
        ])->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.account.id', $shop->id)
            ->assertJsonCount(2, 'data.templates');

        // Gửi lại: đổi số ảnh 1 cái, bỏ 1 cái (ẩn), thêm 1 cái
        $this->sync($token, [
            ['key' => '1', 'name' => 'StripReveal', 'image_count' => 3],
            ['key' => '7', 'name' => 'ScrollBeat', 'image_count' => 2],
        ])->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.hidden', 1);

        // Shop thấy: template tự tạo + 2 template đồng bộ đang hiện, template đồng bộ chỉ xem
        $list = $this->actingAs($shop)->getJson('/api/v1/creator/video-templates')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Shop tự tạo', 'StripReveal', 'ScrollBeat'], array_column($list, 'name'));
        $strip = VideoTemplate::query()->where('source_key', '1')->firstOrFail();
        $this->assertSame(3, $strip->image_count);
        $this->getJson("/api/v1/creator/video-templates/{$strip->id}")->assertOk()
            ->assertJsonPath('data.synced', true)
            ->assertJsonPath('data.video_url', null);
        $this->putJson("/api/v1/creator/video-templates/{$strip->id}", ['name' => 'X', 'video_url' => 'https://www.tiktok.com/@a/video/2', 'image_count' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'VIDEO_TEMPLATE_SYNCED');
        $this->deleteJson("/api/v1/creator/video-templates/{$strip->id}")->assertStatus(409);
        $this->getJson("/api/v1/creator/video-templates/{$own->id}")->assertJsonPath('data.synced', false);

        // Template ẩn không chọn được cho bài video
        $hidden = VideoTemplate::query()->where('source_key', '5')->firstOrFail();
        $this->assertNotNull($hidden->hidden_at);
        $names = array_column($this->getJson('/api/v1/social/video-posts/templates')->assertOk()->json('data'), 'name');
        $this->assertNotContains('FlashMontage', $names);
        $this->assertContains('ScrollBeat', $names);

        // Thêm lại cùng ID: hiện lại (không tạo dòng mới)
        $this->sync($token, [['key' => '5', 'name' => 'FlashMontage', 'image_count' => 12]])->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.hidden', 2);
        $this->assertNull($hidden->fresh()->hidden_at);
        $this->assertSame(4, VideoTemplate::query()->where('user_id', $shop->id)->count());
        $this->assertNull($own->fresh()->hidden_at);
    }

    public function test_switch_off_template_is_not_used_and_survives_sync(): void
    {
        $shop = $this->creator();
        $token = $this->token($shop);
        $this->sync($token, [
            ['key' => '1', 'name' => 'StripReveal', 'image_count' => 2],
            ['key' => '6', 'name' => 'LensPop', 'image_count' => 2],
        ])->assertOk();
        $lens = VideoTemplate::query()->where('source_key', '6')->firstOrFail();

        // Template đồng bộ vẫn tắt được (không sửa được)
        $this->actingAs($shop)->patchJson("/api/v1/creator/video-templates/{$lens->id}/enabled", ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/v1/creator/video-templates')->assertJsonCount(2, 'data');
        $this->assertSame(['StripReveal'], array_column($this->getJson('/api/v1/social/video-posts/templates')->json('data'), 'name'));

        // Sync lại không bật lại
        $this->sync($token, [
            ['key' => '1', 'name' => 'StripReveal', 'image_count' => 2],
            ['key' => '6', 'name' => 'LensPop', 'image_count' => 2],
        ])->assertOk();
        $this->assertFalse($lens->fresh()->is_enabled);

        $this->actingAs($shop)->patchJson("/api/v1/creator/video-templates/{$lens->id}/enabled", ['enabled' => true])
            ->assertOk()->assertJsonPath('data.enabled', true);
        $this->patchJson("/api/v1/creator/video-templates/{$lens->id}/enabled", [])->assertStatus(422);
        $this->actingAs($this->creator())->patchJson("/api/v1/creator/video-templates/{$lens->id}/enabled", ['enabled' => false])->assertNotFound();
    }

    public function test_sync_rules(): void
    {
        $shop = $this->creator();
        $other = $this->creator();
        $token = $this->token($shop);

        $this->sync($this->token(null), [])->assertStatus(409)->assertJsonPath('code', 'VIDEO_ACCOUNT_NOT_ASSIGNED');
        $this->withToken('sai')->putJson('/api/v1/video-templates/sync', ['templates' => []])->assertUnauthorized();

        $this->sync($token, [
            ['key' => '1', 'name' => 'A', 'image_count' => 0],
            ['key' => '1', 'name' => 'a', 'image_count' => 36],
            ['key' => 'x y', 'name' => '', 'image_count' => 2],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'templates.0.image_count', 'templates.1.image_count', 'templates.1.key', 'templates.1.name', 'templates.2.key', 'templates.2.name',
        ]);

        // Mỗi shop riêng: đồng bộ vào shop này không đụng shop khác
        $this->sync($this->token($other), [['key' => '1', 'name' => 'StripReveal', 'image_count' => 2]])->assertOk();
        $this->sync($token, [])->assertOk()->assertJsonPath('data.hidden', 0);
        $this->assertNull(VideoTemplate::query()->where('user_id', $other->id)->firstOrFail()->hidden_at);
    }
}
