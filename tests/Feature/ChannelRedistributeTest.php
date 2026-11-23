<?php

namespace Tests\Feature;

use App\Enums\SocialPostStatus;
use App\Models\Channel;
use App\Models\ContentPlan;
use App\Models\ContentPlanItem;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Nút "Làm mới giờ đăng" của kỳ (tab Kế hoạch): bài của kỳ giữ ngày, đổi giờ theo khung giờ mới. */
class ChannelRedistributeTest extends TestCase
{
    private User $shop;

    private Worker $worker;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00'));
        $this->shop = $this->creator();
        [$this->worker] = Worker::register('dang-'.Str::random(4), Worker::TYPE_PUBLISH);
        $this->worker->users()->attach($this->shop->id);
        $this->channel = $this->channelFor($this->shop, $this->worker, [
            'posts_per_day' => 4,
            // Khung giờ MỚI (đã lưu); bài bên dưới đang ở giờ của khung cũ 11-13, 19-22
            'time_slots' => [['start' => '14:00', 'end' => '18:00']],
        ]);
    }

    private function makePost(string $at, SocialPostStatus $status = SocialPostStatus::Queued): SocialPost
    {
        return SocialPost::query()->create([
            'source' => SocialPost::SOURCE_EXISTING, 'idempotency_key' => (string) Str::uuid(),
            'user_id' => $this->shop->id, 'worker_id' => $this->worker->id, 'status' => $status, 'scheduled_at' => CarbonImmutable::parse($at),
        ]);
    }

    private function item(ContentPlan $plan, int $slot, string $at, string $status, ?SocialPost $post = null): ContentPlanItem
    {
        $item = $plan->items()->create([
            'slot_index' => $slot, 'scheduled_at' => CarbonImmutable::parse($at), 'sample_key' => 'unboxing', 'hook' => 'Hook '.$slot,
            'format' => ContentPlanItem::FORMAT_PHOTO, 'images' => [], 'status' => $status, 'social_post_id' => $post?->id,
        ]);
        $post?->update(['plan_item_id' => $item->id]);

        return $item;
    }

    public function test_redistribute_keeps_day_and_moves_into_new_slots(): void
    {
        $plan = $this->channel->plans()->create([
            'start_date' => '2026-11-21', 'days' => 1, 'status' => ContentPlan::APPROVED,
            'slots' => [
                ['index' => 0, 'at' => '2026-11-21T11:30:00+07:00', 'format' => 'photo'],
                ['index' => 1, 'at' => '2026-11-21T19:30:00+07:00', 'format' => 'photo'],
                ['index' => 2, 'at' => '2026-11-21T21:00:00+07:00', 'format' => 'photo'],
            ],
        ]);
        $draft = $this->item($plan, 0, '2026-11-21 11:30', ContentPlanItem::DRAFT);
        $queued = $this->makePost('2026-11-21 19:30');
        $posted = $this->item($plan, 1, '2026-11-21 19:30', ContentPlanItem::POSTED, $queued);
        // Slot 2 trống. Bài ngoài kỳ (bài thủ công, bài đã đăng) giữ nguyên
        $manualToday = $this->makePost('2026-11-20 12:00');
        $soon = $this->makePost('2026-11-20 08:10');
        $published = $this->makePost('2026-11-21 16:00', SocialPostStatus::Published);

        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$plan->id}/reset-times")
            ->assertOk()
            ->assertJsonPath('data.id', $plan->id)
            ->assertJsonPath('data.moved', 3)
            ->assertJsonPath('data.skipped', 0);

        $inWindow = function ($at, string $day) {
            $at = CarbonImmutable::parse($at);
            $this->assertSame($day, $at->toDateString());
            $this->assertTrue($at->format('H:i') >= '14:00' && $at->format('H:i') < '18:00', 'Giờ mới trong khung: '.$at);
        };
        $draft->refresh();
        $posted->refresh();
        $inWindow($draft->scheduled_at, '2026-11-21');
        $inWindow($posted->scheduled_at, '2026-11-21');
        $this->assertTrue($draft->scheduled_at->lt($posted->scheduled_at), 'Giữ thứ tự bài trong ngày');
        $this->assertTrue($queued->refresh()->scheduled_at->eq($posted->scheduled_at), 'Bài đăng đi cùng bài kế hoạch');
        $this->assertSame('2026-11-20 12:00', $manualToday->refresh()->scheduled_at->format('Y-m-d H:i'));

        $slots = collect($plan->refresh()->slots)->keyBy('index');
        $this->assertTrue(CarbonImmutable::parse($slots[0]['at'])->eq($draft->scheduled_at));
        $inWindow($slots[2]['at'], '2026-11-21');

        $this->assertSame('2026-11-20 08:10', $soon->refresh()->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-21 16:00', $published->refresh()->scheduled_at->format('Y-m-d H:i'));
        $times = collect([$draft->scheduled_at, $posted->scheduled_at, CarbonImmutable::parse($slots[2]['at']), $published->scheduled_at])->sort()->values();
        for ($i = 1; $i < $times->count(); $i++) {
            $this->assertGreaterThanOrEqual(20, $times[$i - 1]->diffInMinutes($times[$i]), 'Cách nhau >= 20 phút');
        }
    }

    public function test_today_without_remaining_window_keeps_time_and_guards(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 17:50:00'));
        $plan = $this->channel->plans()->create([
            'start_date' => '2026-11-20', 'days' => 1, 'status' => ContentPlan::APPROVED,
            'slots' => [['index' => 0, 'at' => '2026-11-20T21:00:00+07:00', 'format' => 'photo']],
        ]);
        $late = $this->makePost('2026-11-20 21:00');
        $this->item($plan, 0, '2026-11-20 21:00', ContentPlanItem::POSTED, $late);
        $url = "/api/v1/creator/channels/{$this->channel->id}/plans/{$plan->id}/reset-times";

        $this->actingAs($this->shop)->postJson($url)
            ->assertOk()->assertJsonPath('data.moved', 0)->assertJsonPath('data.skipped', 1);
        $this->assertSame('21:00', $late->refresh()->scheduled_at->format('H:i'));

        $this->actingAs($this->creator())->postJson($url)->assertNotFound();

        $this->channel->update(['time_slots' => null]);
        $this->actingAs($this->shop)->postJson($url)->assertStatus(422)->assertJsonPath('code', 'NO_TIME_SLOTS');

        $this->channel->update(['time_slots' => [['start' => '14:00', 'end' => '18:00']]]);
        $plan->update(['status' => ContentPlan::CANCELLED]);
        $this->actingAs($this->shop)->postJson($url)->assertStatus(409)->assertJsonPath('code', 'PLAN_CLOSED');
    }
}
