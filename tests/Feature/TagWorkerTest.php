<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\TagJob;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Gắn tag bằng AI: chủ shop chọn sản phẩm -> việc tag -> worker trả tag (a90-fe note/xay-kenh-tiktok/worker-gan-tag.txt). */
class TagWorkerTest extends TestCase
{
    private User $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->creator();
    }

    private function product(string $field = 'anh_san_pham'): Product
    {
        $p = Product::query()->create(['user_id' => $this->shop->id, 'name' => 'Giày '.Str::random(3)]);
        ProductImage::query()->create(['product_id' => $p->id, 'path' => "products/{$p->id}.png", 'field_key' => $field, 'position' => 1]);

        return $p;
    }

    private function tagWorker(): array
    {
        [$worker, $token] = Worker::register('tag-'.Str::random(4), Worker::TYPE_TAG);
        $worker->users()->attach($this->shop->id);

        return [$worker, $token];
    }

    private function start(array $ids)
    {
        return $this->actingAs($this->shop)->postJson('/api/v1/creator/products/tag-jobs', ['product_ids' => $ids]);
    }

    private function worker(string $token, string $method, string $uri, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, "/api/v1/worker-jobs{$uri}", $body);
    }

    private function tags(array $extra = []): array
    {
        return ['kieu' => 'sandal', 'got' => 'thap', 'mau' => ['kem'], 'phong_cach' => ['nhe_nhang'], 'dip' => ['di_choi'], 'ootd' => ['giaysandal', 'giaykem'], ...$extra];
    }

    public function test_choose_products_worker_tags_them(): void
    {
        $a = $this->product();
        $b = $this->product();
        $noField = $this->product('product_image');
        $foreign = Product::query()->create(['user_id' => $this->creator()->id, 'name' => 'Khác']);

        $this->start([$a->id])->assertStatus(409)->assertJsonPath('code', 'TAG_WORKER_REQUIRED');
        [, $token] = $this->tagWorker();
        $this->actingAs($this->shop)->getJson('/api/v1/creator/products')->assertOk()
            ->assertJsonPath('data.0.taggable', false)->assertJsonPath('data.1.taggable', true);
        $this->start([$a->id, $noField->id])->assertStatus(422)->assertJsonValidationErrors('product_ids');
        $this->start([$foreign->id])->assertStatus(422);
        $this->start([$a->id, $b->id])->assertCreated()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.product_count', 2);
        $this->start([$a->id])->assertStatus(409)->assertJsonPath('code', 'TAG_JOB_RUNNING');

        // Chủ shop đã sửa kiểu của b -> AI không ghi đè
        $this->actingAs($this->shop)->putJson("/api/v1/creator/products/{$b->id}/tags", ['tags' => ['kieu' => 'boot']])->assertOk();

        $job = $this->worker($token, 'GET', '')->assertOk()->assertJsonPath('data.type', 'tag')->json('data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($job['request']['products'], 'id'));
        $cover = collect($job['request']['products'])->firstWhere('id', $a->id)['cover'];
        $this->assertSame($a->images()->value('id'), $cover['image_id']);
        $this->assertNotEmpty($cover['thumb_url']);

        $res = $this->worker($token, 'POST', "/{$job['id']}", ['status' => 'completed', 'items' => [
            ['id' => $a->id, 'image_id' => $cover['image_id'], 'ai_label' => 'Sandal quai mảnh kem', 'tags' => $this->tags()],
            ['id' => $b->id, 'image_id' => $b->images()->value('id'), 'ai_label' => 'Sandal đen', 'tags' => $this->tags()],
            ['id' => $noField->id, 'image_id' => 1, 'ai_label' => 'x', 'tags' => $this->tags()], // không thuộc việc -> bỏ
        ]])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $res['result']['updated']);
        $this->assertSame(['kieu'], $res['result']['skipped'][$b->id]);
        $this->assertSame('Sandal quai mảnh kem', $a->fresh()->ai_label);
        $this->assertSame('boot', $b->fresh()->tags['kieu']);
        $this->assertNull($noField->fresh()->tags);

        $this->actingAs($this->shop)->getJson('/api/v1/creator/products/tag-jobs/latest')->assertOk()
            ->assertJsonPath('data.tag_worker', true)->assertJsonPath('data.job.status', 'completed')
            ->assertJsonPath('data.job.result.updated', 2);
        $this->start([$a->id])->assertCreated();
    }

    public function test_failed_and_expired_tag_jobs(): void
    {
        $a = $this->product();
        [, $token] = $this->tagWorker();
        [, $other] = $this->tagWorker();
        $this->start([$a->id])->assertCreated();
        $id = $this->worker($token, 'GET', '')->json('data.id');
        $this->worker($other, 'POST', "/{$id}", ['status' => 'completed', 'items' => []])->assertStatus(409);
        $this->travel(TagJob::LEASE_MINUTES + 1)->minutes();
        $this->assertSame($id, $this->worker($other, 'GET', '')->json('data.id'));
        $this->worker($other, 'POST', "/{$id}", ['status' => 'failed', 'error_code' => 'CLAUDE_ERROR', 'error_message' => 'Claude lỗi'])->assertOk();
        $this->actingAs($this->shop)->getJson('/api/v1/creator/products/tag-jobs/latest')
            ->assertJsonPath('data.job.status', 'failed')->assertJsonPath('data.job.error_message', 'Claude lỗi');
    }
}
