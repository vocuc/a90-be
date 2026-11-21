<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Worker điều phối: 1 token / máy nhận việc dựng nội dung / lập chủ đề / gắn tag (a90-fe note/worker-dieu-phoi.txt). */
class DispatchWorkerTest extends TestCase
{
    private User $shop;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai90.post_samples_enabled' => ['*']]);
        $this->shop = $this->creator();
        $this->channel = $this->channelFor($this->shop);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 08:00:00'));
    }

    private function dispatcher(): array
    {
        [$worker, $token] = Worker::register('may-'.Str::random(4), Worker::TYPE_DISPATCH);
        $worker->users()->attach($this->shop->id);

        return [$worker, $token];
    }

    private function worker(string $token, string $method, string $uri, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, "/api/v1/worker-jobs{$uri}", $body);
    }

    private function taggableProduct(): Product
    {
        $p = Product::query()->create(['user_id' => $this->shop->id, 'name' => 'Giày']);
        ProductImage::query()->create(['product_id' => $p->id, 'path' => "products/{$p->id}.png", 'field_key' => 'anh_san_pham', 'position' => 1]);

        return $p;
    }

    public function test_one_dispatcher_token_takes_topic_and_tag_jobs_by_requested_types(): void
    {
        [$worker, $token] = $this->dispatcher();
        $this->assertSame(['content', 'topic', 'tag', 'plan'], $worker->jobTypes());
        $p = $this->taggableProduct();

        // Shop có worker điều phối = có worker lập chủ đề / gắn tag / dựng nội dung
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/topic-jobs", ['count' => 3])->assertCreated();
        $this->actingAs($this->shop)->postJson('/api/v1/creator/products/tag-jobs', ['product_ids' => [$p->id]])->assertCreated();
        $this->actingAs($this->shop)->getJson('/api/v1/creator/workers')->assertOk()
            ->assertJsonPath('data.0.functions', ['content', 'topic', 'tag', 'plan']);

        // Chỉ hỏi loại còn chỗ: ?types=tag -> việc gắn tag; ?types=image (không làm được) -> không có việc
        $this->worker($token, 'GET', '?types=image')->assertNoContent();
        $tag = $this->worker($token, 'GET', '?types=tag')->assertOk()->assertJsonPath('data.type', 'tag')->json('data');
        $topic = $this->worker($token, 'GET', '')->assertOk()->assertJsonPath('data.type', 'topic')->json('data');
        $this->worker($token, 'GET', '')->assertNoContent();

        // Gia hạn dùng chung mọi loại
        WorkerJob::query()->whereKey($topic['id'])->update(['lease_expires_at' => now()->addMinute()]);
        $this->worker($token, 'POST', "/{$topic['id']}/extend")->assertOk();
        $this->assertTrue(WorkerJob::query()->findOrFail($topic['id'])->lease_expires_at->greaterThan(now()->addMinutes(25)));

        $this->worker($token, 'POST', "/{$topic['id']}", ['status' => 'completed', 'topics' => [['name' => 'Đi chúc Tết', 'tags' => ['dip' => ['tet']]]]])
            ->assertOk()->assertJsonPath('data.result.created', ['Đi chúc Tết']);
        $this->worker($token, 'POST', "/{$tag['id']}", ['status' => 'completed', 'items' => [[
            'id' => $p->id, 'image_id' => $p->images()->value('id'), 'ai_label' => 'Sandal kem',
            'tags' => ['kieu' => 'sandal', 'got' => 'thap', 'mau' => ['kem'], 'phong_cach' => ['basic'], 'dip' => ['di_choi']],
        ]]])->assertOk();
        $this->assertSame('Sandal kem', $p->fresh()->ai_label);
        $this->worker($token, 'POST', "/{$topic['id']}/extend")->assertStatus(409);
    }

    public function test_admin_creates_dispatch_instead_of_merged_types(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/workers', ['name' => 'may-1', 'type' => 'topic'])->assertSessionHasErrors('type');
        $this->actingAs($admin)->post('/admin/workers', ['name' => 'may-1', 'type' => 'dispatch'])->assertRedirect();
        $this->assertSame('dispatch', Worker::query()->where('name', 'may-1')->value('type'));
        // Worker cũ loại lập chủ đề vẫn chạy cho tới khi admin đổi sang Điều phối
        [$old] = Worker::register('cu', Worker::TYPE_TOPIC);
        $this->actingAs($admin)->patch("/admin/workers/{$old->id}/type", ['type' => 'dispatch'])->assertSessionHas('success');
        $this->assertSame('dispatch', $old->fresh()->type);
    }
}
