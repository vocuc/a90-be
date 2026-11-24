<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelTopic;
use App\Models\Product;
use App\Models\TopicJob;
use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Worker lập chủ đề: chủ shop bấm "Xây dựng chủ đề tự động" -> việc topic -> worker trả chủ đề (a90-fe note/xay-kenh-tiktok/worker-lap-chu-de.txt). */
class TopicWorkerTest extends TestCase
{
    private User $shop;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai90.post_samples_enabled' => ['*']]);
        $this->shop = $this->creator();
        $this->channel = $this->channelFor($this->shop, null, ['persona' => ['age_range' => '20-30']]);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 08:00:00'));
    }

    private function topicWorker(): array
    {
        [$worker, $token] = Worker::register('chu-de-'.Str::random(4), Worker::TYPE_TOPIC);
        $worker->users()->attach($this->shop->id);

        return [$worker, $token];
    }

    private function start(array $body = ['count' => 5, 'note' => 'Tập trung Tết'])
    {
        return $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/topic-jobs", $body);
    }

    private function worker(string $token, string $method, string $uri, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, "/api/v1/worker-jobs{$uri}", $body);
    }

    public function test_auto_build_creates_job_worker_returns_topics(): void
    {
        $this->start()->assertStatus(409)->assertJsonPath('code', 'TOPIC_WORKER_REQUIRED');
        [, $token] = $this->topicWorker();
        $this->channel->topics()->create(['name' => 'Đi làm', 'source' => 'owner', 'status' => 'active']);
        Product::query()->create(['user_id' => $this->shop->id, 'name' => 'A', 'tags' => ['kieu' => 'giaycaogot', 'dip' => ['tet']]]);

        $this->start(['count' => 0])->assertStatus(422);
        $this->start()->assertCreated()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.count', 5);
        $this->start()->assertStatus(409)->assertJsonPath('code', 'TOPIC_JOB_RUNNING');
        $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/topics")->assertOk()
            ->assertJsonPath('data.topic_worker', true)->assertJsonPath('data.job.status', 'queued');

        $job = $this->worker($token, 'GET', '')->assertOk()->assertJsonPath('data.type', 'topic')->json('data');
        $this->assertSame(5, $job['request']['count']);
        $this->assertSame('Tập trung Tết', $job['request']['note']);
        $this->assertSame('Đi làm', $job['request']['existing_topics'][0]['name']);
        $this->assertSame('Sự kiện', $job['request']['categories']['su_kien']);
        $this->assertSame(1, $job['request']['product_tags']['counts']['kieu']['giaycaogot']);
        $this->assertNotEmpty($job['request']['holidays']);
        $this->worker($token, 'GET', '')->assertNoContent();

        $res = $this->worker($token, 'POST', "/{$job['id']}", ['status' => 'completed', 'topics' => [
            ['name' => 'Đi chúc Tết', 'category' => 'su_kien', 'note' => '  Đi chúc Tết nhà nội ngoại, sang mà đi cả ngày được  '],
            ['name' => 'đi làm', 'note' => 'x'],                      // trùng chủ đề có sẵn -> bỏ qua
            ['name' => 'Đi hẹn hò', 'category' => 'xyz'],             // không ghi chú -> lệnh sáng tạo tự do; nhóm sai -> đoán theo tên
            ['name' => str_repeat('a', 81)],
            ['name' => ''],
        ]])->assertOk()->json('data');
        $this->assertSame(['Đi chúc Tết', 'Đi hẹn hò'], $res['result']['created']);
        $this->assertSame(['đi làm'], $res['result']['skipped']);
        $this->assertCount(2, $res['result']['errors']);

        $topic = ChannelTopic::query()->where('name', 'Đi chúc Tết')->sole();
        $this->assertSame('ai', $topic->source);
        $this->assertSame('active', $topic->status);
        $this->assertSame('Đi chúc Tết nhà nội ngoại, sang mà đi cả ngày được', $topic->note);
        $this->assertNull(ChannelTopic::query()->where('name', 'Đi hẹn hò')->value('note'));
        // Nhóm chủ đề (2026-11-24): worker gửi nhóm; sai / thiếu thì đoán theo tên
        $this->assertSame('su_kien', $topic->category);
        $this->assertSame('hang_ngay', ChannelTopic::query()->where('name', 'Đi hẹn hò')->value('category'));
        $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/topics")->assertOk()
            ->assertJsonPath('data.job.status', 'completed')->assertJsonPath('data.job.result.created', 2);
        // Đã xong -> bấm lượt mới được
        $this->start()->assertCreated();
    }

    public function test_failed_and_expired_jobs(): void
    {
        [, $token] = $this->topicWorker();
        [, $other] = $this->topicWorker();
        $this->start()->assertCreated();
        $id = $this->worker($token, 'GET', '')->json('data.id');

        // Worker khác không trả kết quả được; quá hạn -> giao lại
        $this->worker($other, 'POST', "/{$id}", ['status' => 'completed', 'topics' => []])->assertStatus(409);
        $this->travel(TopicJob::LEASE_MINUTES + 1)->minutes();
        $this->assertSame($id, $this->worker($other, 'GET', '')->json('data.id'));
        $this->worker($token, 'POST', "/{$id}", ['status' => 'failed', 'error_code' => 'CLAUDE_ERROR'])->assertStatus(409);

        $this->worker($other, 'POST', "/{$id}", ['status' => 'failed', 'error_code' => 'CLAUDE_ERROR', 'error_message' => 'Claude lỗi'])->assertOk();
        $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/topics")
            ->assertJsonPath('data.job.status', 'failed')->assertJsonPath('data.job.error_message', 'Claude lỗi');
        $this->assertSame(0, ChannelTopic::query()->count());
    }

    public function test_only_topic_worker_of_attached_shop_gets_job(): void
    {
        [$worker, $token] = $this->topicWorker();
        [, $imageToken] = Worker::register('anh-'.Str::random(4), Worker::TYPE_IMAGE);
        $this->start()->assertCreated();
        $worker->users()->detach($this->shop->id);
        $this->worker($token, 'GET', '')->assertNoContent();
        $this->worker($imageToken, 'GET', '')->assertNoContent();
        $worker->users()->attach($this->shop->id);
        $this->worker($token, 'GET', '')->assertOk()->assertJsonPath('data.type', 'topic');
    }
}
