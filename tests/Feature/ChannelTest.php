<?php

namespace Tests\Feature;

use App\Enums\SocialPostStatus;
use App\Models\Channel;
use App\Models\Hashtag;
use App\Models\ShopAiSetting;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Kênh (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.1, 5.1, 5.2, 5.6). */
class ChannelTest extends TestCase
{
    private function publishWorker(User $shop, string $name = 'dang-bai'): Worker
    {
        [$worker] = Worker::register($name.'-'.Str::random(4), Worker::TYPE_PUBLISH);
        $worker->users()->attach($shop->id);

        return $worker;
    }

    private function rawPost(array $attrs): SocialPost
    {
        return SocialPost::query()->create(['source' => SocialPost::SOURCE_EXISTING, 'idempotency_key' => (string) Str::uuid(), ...$attrs]);
    }

    private function createChannel(User $shop, Worker $worker, string $name = 'Kênh chính'): TestResponse
    {
        return $this->actingAs($shop)->postJson('/api/v1/creator/channels', ['name' => $name, 'worker_id' => $worker->id]);
    }

    private function updateChannel(User $shop, int $id, array $body): TestResponse
    {
        return $this->actingAs($shop)->putJson("/api/v1/creator/channels/{$id}", $body);
    }

    public function test_first_channel_copies_legacy_shop_ai_settings_and_hashtags(): void
    {
        $shop = $this->creator();
        ShopAiSetting::query()->create(['owner_id' => $shop->id, 'title_max' => 40, 'content_max' => 300, 'requirements' => str_repeat('a', 3500)]);
        $shop->shopHashtags()->sync(Hashtag::syncPivot(['giaynu', 'tet2027']));

        $first = $this->createChannel($shop, $this->publishWorker($shop))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Kênh chính')
            ->assertJsonMissingPath('data.title_max')
            ->assertJsonMissingPath('data.content_max')
            ->assertJsonPath('data.hashtags', ['giaynu', 'tet2027'])
            ->assertJsonPath('data.plan_days', 7)
            ->assertJsonPath('data.posts_per_day', 3)
            ->assertJsonPath('data.missing', ['persona', 'time_slots', 'lineup', 'text_templates']);
        $this->assertSame(3000, mb_strlen($first->json('data.requirements')), 'Yêu cầu cũ cắt về 3000 ký tự');

        // Kênh thứ hai: cài đặt mặc định, không chép lại (bỏ qua khi đang giới hạn 1 kênh / shop)
        if (Channel::MAX_PER_SHOP > 1) {
            $this->createChannel($shop, $this->publishWorker($shop), 'Kênh phụ')
                ->assertCreated()
                ->assertJsonPath('data.hashtags', []);
        }
    }

    public function test_worker_must_be_assigned_and_free(): void
    {
        $shop = $this->creator();
        $worker = $this->publishWorker($shop);

        [$foreign] = Worker::register('khong-gan', Worker::TYPE_PUBLISH);
        $this->createChannel($shop, $foreign)->assertStatus(422)->assertJsonValidationErrors('worker_id');

        [$image] = Worker::register('tao-anh', Worker::TYPE_IMAGE);
        $image->users()->attach($shop->id);
        $this->createChannel($shop, $image)->assertStatus(422)->assertJsonValidationErrors('worker_id');

        $this->createChannel($shop, $worker)->assertCreated();

        // Worker đã thuộc kênh: shop khác cùng được gắn worker đó cũng không dùng được
        $other = $this->creator();
        $worker->users()->attach($other->id);
        $this->createChannel($other, $worker, 'Trùng worker')->assertStatus(422)->assertJsonValidationErrors('worker_id');

        $this->actingAs($shop)->getJson('/api/v1/creator/channel-workers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $worker->id)
            ->assertJsonPath('data.0.channel_id', Channel::query()->value('id'));
    }

    public function test_channel_limit_per_shop(): void
    {
        $shop = $this->creator();
        for ($i = 0; $i < Channel::MAX_PER_SHOP; $i++) {
            $this->channelFor($shop);
        }
        $this->createChannel($shop, $this->publishWorker($shop))->assertStatus(409)->assertJsonPath('code', 'CHANNEL_LIMIT');
    }

    public function test_update_config_persona_and_ai_settings(): void
    {
        $shop = $this->creator();
        $id = $this->createChannel($shop, $this->publishWorker($shop))->json('data.id');

        $this->updateChannel($shop, $id, [
            'plan_days' => 3,
            'posts_per_day' => 4,
            'time_slots' => [['start' => '19:00', 'end' => '22:00'], ['start' => '11:00', 'end' => '13:00']],
            // Ô "% bài video" đã bỏ (tính theo tuyến): gửi lên bị bỏ qua
            'video_percent' => 25,
            'auto_approve_lineup' => true,
            'auto_approve_plan' => true,
            'persona' => ['age_range' => ' 18-25 ', 'tone' => 'xưng shop, gọi các nàng', 'la' => 'bỏ qua', 'gender' => ''],
            'requirements' => '  Không dùng icon cuối tiêu đề  ',
            'hashtags' => ['#tet2027', 'giaytet2027', 'TET2027'],
            // Ô tiêu đề / nội dung tối đa đã bỏ (2026-11-25): gửi lên bị bỏ qua
            'title_max' => 60,
        ])->assertOk()
            ->assertJsonPath('data.plan_days', 3)
            ->assertJsonPath('data.posts_per_day', 4)
            ->assertJsonPath('data.time_slots', [['start' => '11:00', 'end' => '13:00'], ['start' => '19:00', 'end' => '22:00']])
            ->assertJsonPath('data.video_percent', 0)
            ->assertJsonPath('data.auto_approve_lineup', true)
            ->assertJsonPath('data.auto_approve_templates', false)
            ->assertJsonPath('data.auto_approve_plan', true)
            ->assertJsonPath('data.persona', ['age_range' => '18-25', 'tone' => 'xưng shop, gọi các nàng'])
            ->assertJsonPath('data.requirements', 'Không dùng icon cuối tiêu đề')
            ->assertJsonPath('data.hashtags', ['tet2027', 'giaytet2027'])
            ->assertJsonMissingPath('data.title_max')
            ->assertJsonPath('data.missing', ['lineup', 'text_templates']);
        $this->assertSame(\App\Models\SocialPost::TITLE_MAX, Channel::query()->find($id)->aiSettings()['title_max']);

        $this->updateChannel($shop, $id, ['time_slots' => [['start' => '11:00', 'end' => '13:00'], ['start' => '12:30', 'end' => '14:00']]])
            ->assertStatus(422)->assertJsonValidationErrors('time_slots');
        $this->updateChannel($shop, $id, ['time_slots' => [['start' => '13:00', 'end' => '11:00']]])
            ->assertStatus(422)->assertJsonValidationErrors('time_slots');
        $this->updateChannel($shop, $id, ['requirements' => str_repeat('a', 3001), 'plan_days' => 31, 'posts_per_day' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['requirements', 'plan_days', 'posts_per_day']);
        $this->updateChannel($shop, $id, ['requirements' => str_repeat('a', 3000)])->assertOk();

        // Kênh của shop khác / không phải chủ shop
        $this->actingAs($this->creator())->getJson("/api/v1/creator/channels/{$id}")->assertNotFound();
        $this->actingAs($this->buyer())->getJson('/api/v1/creator/channels')->assertForbidden();
    }

    public function test_changing_worker_moves_waiting_posts(): void
    {
        $shop = $this->creator();
        $old = $this->publishWorker($shop, 'cu');
        $channel = $this->channelFor($shop, $old);
        $waiting = $this->rawPost(['user_id' => $shop->id, 'worker_id' => $old->id, 'status' => SocialPostStatus::Queued]);
        $publishing = $this->rawPost(['user_id' => $shop->id, 'worker_id' => $old->id, 'status' => SocialPostStatus::Publishing]);
        $this->assertSame($channel->id, $waiting->channel_id, 'Bài nhận kênh theo worker');

        $new = $this->publishWorker($shop, 'moi');
        $this->updateChannel($shop, $channel->id, ['worker_id' => $new->id])->assertOk()->assertJsonPath('data.worker.id', $new->id);

        $this->assertSame($new->id, $waiting->fresh()->worker_id);
        $this->assertSame($old->id, $publishing->fresh()->worker_id, 'Bài worker cũ đang đăng giữ nguyên');
    }

    public function test_delete_blocked_while_posts_unfinished(): void
    {
        $shop = $this->creator();
        $worker = $this->publishWorker($shop);
        $channel = $this->channelFor($shop, $worker);
        $post = $this->rawPost(['user_id' => $shop->id, 'worker_id' => $worker->id, 'status' => SocialPostStatus::Queued]);

        $this->actingAs($shop)->deleteJson("/api/v1/creator/channels/{$channel->id}")
            ->assertStatus(409)->assertJsonPath('code', 'CHANNEL_HAS_POSTS');

        $post->update(['status' => SocialPostStatus::Published]);
        $this->actingAs($shop)->deleteJson("/api/v1/creator/channels/{$channel->id}")->assertNoContent();
        $this->assertNull($post->fresh()->channel_id);
    }

    public function test_list_and_post_samples(): void
    {
        $shop = $this->creator();
        $worker = $this->publishWorker($shop);
        $channel = $this->channelFor($shop, $worker);
        $this->rawPost(['user_id' => $shop->id, 'worker_id' => $worker->id, 'status' => SocialPostStatus::Queued, 'scheduled_at' => now()->addHour()]);
        $this->channelFor($this->creator());

        $this->actingAs($shop)->getJson('/api/v1/creator/channels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $channel->id)
            ->assertJsonPath('data.0.upcoming_posts', 1)
            ->assertJsonPath('meta.max', Channel::MAX_PER_SHOP);

        $samples = $this->actingAs($shop)->getJson('/api/v1/creator/channels/post-samples')->assertOk()->json('data');
        $keys = array_column($samples, 'key');
        $this->assertContains('unboxing', $keys);
        $this->assertContains('top_n', $keys);
        $this->assertSame(count($keys), count(array_unique($keys)), 'Key bài viết mẫu không trùng');
    }

    public function test_publishing_requires_worker_in_a_channel(): void
    {
        $shop = $this->buyer();
        $worker = $this->publishWorker($shop);
        $post = $this->rawPost(['user_id' => $shop->id, 'status' => SocialPostStatus::PendingReview]);
        $body = ['worker_id' => $worker->id, 'title' => 'x', 'platforms' => ['tiktok'], 'images' => [['generation_id' => 1, 'output_id' => 1]]];

        $this->actingAs($shop)->postJson("/api/v1/social/posts/{$post->id}/publish", $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('worker_id');
    }
}
