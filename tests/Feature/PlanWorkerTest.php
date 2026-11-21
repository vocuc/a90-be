<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ContentPlan;
use App\Models\PlanJob;
use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Lập kế hoạch mới bằng worker điều phối (a90-fe note/xay-kenh-tiktok/worker-lap-ke-hoach.txt). */
class PlanWorkerTest extends TestCase
{
    private User $shop;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->creator();
        [$publisher] = Worker::register('dang-'.Str::random(4), Worker::TYPE_PUBLISH);
        $this->channel = $this->channelFor($this->shop, $publisher, [
            'time_slots' => [['start' => '11:00', 'end' => '13:00']],
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 08:00:00'));
    }

    private function dispatcher(): string
    {
        [$worker, $token] = Worker::register('dieu-phoi-'.Str::random(4), Worker::TYPE_DISPATCH);
        $worker->users()->attach($this->shop->id);

        return $token;
    }

    private function start(array $body = ['note' => 'Tập trung Tết'])
    {
        return $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plan-jobs", $body);
    }

    private function worker(string $token, string $method, string $uri, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, "/api/v1/worker-jobs{$uri}", $body);
    }

    private function plan(string $status): ContentPlan
    {
        return $this->channel->plans()->create(['start_date' => '2026-11-22', 'days' => 7, 'status' => $status, 'slots' => []]);
    }

    public function test_button_creates_job_dispatcher_runs_and_reports(): void
    {
        $this->start()->assertStatus(409)->assertJsonPath('code', 'PLAN_WORKER_REQUIRED');
        $token = $this->dispatcher();
        $this->start()->assertStatus(409)->assertJsonPath('code', 'TOPICS_REQUIRED');
        $this->channel->topics()->create(['name' => 'Đi làm', 'source' => 'owner', 'status' => 'active']);

        $draft = $this->plan(ContentPlan::DRAFT);
        $this->start()->assertStatus(409)->assertJsonPath('code', 'PLAN_DRAFT_PENDING');
        $draft->update(['status' => ContentPlan::CANCELLED]);

        $this->start()->assertCreated()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.note', 'Tập trung Tết');
        $this->start()->assertStatus(409)->assertJsonPath('code', 'PLAN_JOB_RUNNING');
        $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/plan-job")->assertOk()
            ->assertJsonPath('data.plan_worker', true)->assertJsonPath('data.job.status', 'queued');

        // Máy chỉ còn chỗ cho dựng nội dung -> không nhận việc lập kế hoạch
        $this->worker($token, 'GET', '?types=content')->assertNoContent();
        $job = $this->worker($token, 'GET', '?types=plan')->assertOk()->assertJsonPath('data.type', 'plan')->json('data');
        $this->assertSame($this->channel->id, $job['request']['channel_id']);
        $this->assertSame('Tập trung Tết', $job['request']['note']);
        $this->worker($token, 'POST', "/{$job['id']}/extend")->assertOk();

        // Skill gửi kỳ trong lúc chạy
        $plan = $this->plan(ContentPlan::DRAFT);
        $res = $this->worker($token, 'POST', "/{$job['id']}", ['status' => 'completed', 'summary' => 'Đã lập 7/7 bài, kỳ chờ duyệt.'])
            ->assertOk()->json('data');
        $this->assertSame($plan->id, $res['result']['plan_id']);
        $this->assertSame('draft', $res['result']['plan_status']);

        $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/plan-job")->assertOk()
            ->assertJsonPath('data.job.status', 'completed')
            ->assertJsonPath('data.job.result.summary', 'Đã lập 7/7 bài, kỳ chờ duyệt.');
    }

    public function test_failed_job_and_channel_not_ready(): void
    {
        $token = $this->dispatcher();
        $this->channel->topics()->create(['name' => 'Đi làm', 'source' => 'owner', 'status' => 'active']);
        $this->channel->update(['time_slots' => null]);
        $this->start()->assertStatus(409)->assertJsonPath('code', 'CHANNEL_NOT_READY');
        $this->channel->update(['time_slots' => [['start' => '11:00', 'end' => '13:00']]]);

        $this->start()->assertCreated();
        $job = $this->worker($token, 'GET', '')->assertOk()->assertJsonPath('data.type', 'plan')->json('data');
        $this->worker($token, 'POST', "/{$job['id']}", ['status' => 'failed', 'error_code' => 'CLAUDE_TIMEOUT', 'error_message' => 'quá giờ'])->assertOk();
        $this->assertSame('failed', PlanJob::query()->find($job['id'])->status);
        $this->start()->assertCreated();
    }

    public function test_delete_only_cancelled_plan(): void
    {
        $draft = $this->plan(ContentPlan::DRAFT);
        $url = "/api/v1/creator/channels/{$this->channel->id}/plans/{$draft->id}";
        $this->actingAs($this->shop)->deleteJson($url)->assertStatus(409)->assertJsonPath('code', 'PLAN_NOT_CANCELLED');
        $this->actingAs($this->creator())->deleteJson($url)->assertNotFound();

        $draft->update(['status' => ContentPlan::CANCELLED]);
        $this->actingAs($this->shop)->deleteJson($url)->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertNull(ContentPlan::query()->find($draft->id));
    }

    public function test_fill_button_creates_fill_job_for_plan(): void
    {
        $token = $this->dispatcher();
        $this->channel->topics()->create(['name' => 'Đi làm', 'source' => 'owner', 'status' => 'active']);
        $draft = $this->plan(ContentPlan::DRAFT);
        $draft->update(['slots' => [['index' => 0, 'at' => '2026-11-22T12:00:00+07:00', 'format' => 'photo']]]);
        $url = "/api/v1/creator/channels/{$this->channel->id}/plan-jobs";

        // Kỳ chờ duyệt vẫn bổ sung được (không bị chặn PLAN_DRAFT_PENDING)
        $this->actingAs($this->shop)->postJson($url, ['plan_id' => $draft->id, 'note' => 'Bổ sung'])->assertCreated()
            ->assertJsonPath('data.fill_plan_id', $draft->id);
        $job = $this->worker($token, 'GET', '?types=plan')->assertOk()->json('data');
        $this->assertSame($draft->id, $job['request']['plan_id']);
        $res = $this->worker($token, 'POST', "/{$job['id']}", ['status' => 'completed', 'summary' => 'Đã bổ sung 1 bài.'])->assertOk()->json('data');
        $this->assertSame($draft->id, $res['result']['plan_id']);

        // Kỳ hết slot thiếu (slot đã qua giờ) -> PLAN_FULL; kỳ đã huỷ -> PLAN_CLOSED
        $draft->update(['slots' => [['index' => 0, 'at' => '2026-11-21T08:10:00+07:00', 'format' => 'photo']]]);
        $this->actingAs($this->shop)->postJson($url, ['plan_id' => $draft->id])->assertStatus(409)->assertJsonPath('code', 'PLAN_FULL');
        $draft->update(['status' => ContentPlan::CANCELLED]);
        $this->actingAs($this->shop)->postJson($url, ['plan_id' => $draft->id])->assertStatus(409)->assertJsonPath('code', 'PLAN_CLOSED');
    }
}
