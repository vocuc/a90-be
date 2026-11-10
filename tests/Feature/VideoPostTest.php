<?php

namespace Tests\Feature;

use App\Models\ImageBatch;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VideoTemplate;
use App\Models\Worker;
use App\Services\Social\SocialContentWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Bài đăng video có lịch (note/lap-lich-worker.txt, note/gop-luong-dang-bai.txt ở repo frontend). */
class VideoPostTest extends TestCase
{
    public ?array $aiResult = ['title' => 'Video mới', 'content' => 'Nội dung video', 'hashtags' => []];

    public int $aiCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai90.social.ai_content' => true]);
        $this->app->instance(SocialContentWriter::class, new class($this) implements SocialContentWriter
        {
            public function __construct(private VideoPostTest $test) {}

            public function write(array $request, array $images): ?array
            {
                $this->test->aiCalls++;

                return $this->test->aiResult;
            }
        });
    }

    // ---- Helpers ----

    private function price(): int
    {
        return config('ai90.defaults')['social.ai_content_price'];
    }

    private function template(User $shop, int $imageCount, string $name = 'Mẫu'): VideoTemplate
    {
        return VideoTemplate::query()->create([
            'user_id' => $shop->id, 'name' => $name, 'video_url' => 'https://www.tiktok.com/@a/video/1', 'image_count' => $imageCount,
        ]);
    }

    /** Sản phẩm có $images ảnh đã tạo xong (1 lô gắn sản phẩm). */
    private function productWithImages(User $shop, int $images, string $name = 'SP'): Product
    {
        $product = Product::query()->create(['user_id' => $shop->id, 'name' => $name]);
        $id = $this->actingAs($shop)->post('/api/v1/image-batches', [
            'items' => [[
                'creative_id' => $this->publishedCreative($this->creator())->id,
                'quantity' => $images,
                'inputs' => ['product_image' => UploadedFile::fake()->image('p.png', 64, 64), 'aspectRatio' => '1:1'],
            ]],
            'idempotency_key' => (string) Str::uuid(),
            'product_id' => $product->id,
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->runWorker(array_fill(0, $images, null));

        return $product;
    }

    /** Worker đăng bài gắn cho shop: [worker, token]. */
    private function worker(User $shop, bool $assigned = true): array
    {
        [$worker, $token] = Worker::register('may-'.Str::random(5), Worker::TYPE_PUBLISH);
        if ($assigned) {
            $worker->users()->attach($shop->id);
        }

        return [$worker, $token];
    }

    private function auto(User $shop, Worker $worker, array $extra = []): TestResponse
    {
        return $this->actingAs($shop)->postJson('/api/v1/social/video-posts/auto', [
            'worker_id' => $worker->id,
            'count' => 3,
            'first_at' => now()->addHour()->toIso8601String(),
            'gap_min' => 40,
            'gap_max' => 60,
            'idempotency_key' => (string) Str::uuid(),
            ...$extra,
        ]);
    }

    private function nextJob(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/post-jobs');
    }

    private function submit(string $token, int $jobId, array $body = ['status' => 'completed']): TestResponse
    {
        return $this->withToken($token)->postJson("/api/v1/post-jobs/{$jobId}", $body);
    }

    // ---- Tests ----

    public function test_auto_creates_scheduled_video_posts_with_ai_content(): void
    {
        $shop = $this->creator(credit: 100000);
        $template = $this->template($shop, 2, 'Mở hộp');
        $this->template($shop, 5, 'Quá nhiều ảnh'); // không sản phẩm nào đủ 5 ảnh -> không được chọn
        $product = $this->productWithImages($shop, 3);
        [$worker] = $this->worker($shop);
        $balance = $this->balance($shop);
        $firstAt = now()->addHour()->startOfMinute();

        $data = $this->auto($shop, $worker, ['first_at' => $firstAt->toIso8601String()])->assertCreated()->json('data');
        $this->assertSame(3, $data['created']);
        $this->assertNull($data['stopped']);
        $this->assertSame($balance - 3 * $this->price(), $this->balance($shop));

        $posts = SocialPost::query()->whereIn('id', $data['post_ids'])->orderBy('id')->get();
        $this->assertTrue($posts[0]->scheduled_at->equalTo($firstAt));
        foreach ($posts as $i => $post) {
            $this->assertSame('video', $post->type);
            $this->assertSame('queued', $post->status->value);
            $this->assertSame('Video mới', $post->title);
            $this->assertSame($template->id, $post->video_template_id);
            $this->assertSame(2, $post->video_image_count);
            $this->assertSame($product->id, $post->product_id);
            $this->assertSame($worker->id, $post->worker_id);
            $this->assertSame(2, $post->images()->count());
            // Không chọn nền tảng: mặc định TikTok
            $this->assertSame(['tiktok'], $post->platforms);
            $this->assertSame('queued', $post->tiktok_status->value);
            if ($i > 0) {
                $gap = $posts[$i - 1]->scheduled_at->diffInMinutes($post->scheduled_at);
                $this->assertTrue($gap >= 40 && $gap <= 60, "Khoảng cách {$gap} phút");
            }
        }

        // Danh sách bài đăng chung, lọc được bài video
        $this->getJson('/api/v1/social/posts?type=video')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'video')
            ->assertJsonPath('data.0.video.template.name', 'Mở hộp')
            ->assertJsonPath('data.0.video.worker.id', $worker->id)
            ->assertJsonPath('data.0.video.product.id', $product->id);
    }

    public function test_auto_skips_inactive_products(): void
    {
        $shop = $this->creator(credit: 100000);
        $this->template($shop, 1);
        $off = $this->productWithImages($shop, 3, 'Đã tắt');
        $off->update(['is_active' => false]);
        [$worker, $token] = $this->worker($shop);

        // Chỉ có sản phẩm đang tắt: không có sản phẩm nào để tạo
        $this->auto($shop, $worker)->assertStatus(422)->assertJsonPath('code', 'VIDEO_NOT_ENOUGH_IMAGES');
        $this->withToken($token)->getJson('/api/v1/video-images?count=1')->assertStatus(422)->assertJsonPath('code', 'NOT_ENOUGH_IMAGES');

        // Có thêm sản phẩm đang hoạt động: mọi bài đều dùng sản phẩm đó
        $on = $this->productWithImages($shop, 1, 'Đang bật');
        $data = $this->auto($shop, $worker)->assertCreated()->json('data');
        $this->assertSame(3, $data['created']);
        $this->assertSame([$on->id], SocialPost::query()->whereIn('id', $data['post_ids'])->distinct()->pluck('product_id')->all());
        $this->withToken($token)->getJson('/api/v1/video-images?count=1')->assertOk()->assertJsonPath('data.product.id', $on->id);
    }

    public function test_stops_when_credit_runs_out_and_reports_reasons(): void
    {
        $shop = $this->creator(credit: 100000);
        [$worker] = $this->worker($shop);

        $this->auto($shop, $worker)->assertStatus(422)->assertJsonPath('code', 'VIDEO_NO_TEMPLATE');
        $this->template($shop, 2);
        $this->auto($shop, $worker)->assertStatus(422)->assertJsonPath('code', 'VIDEO_NOT_ENOUGH_IMAGES');
        $this->productWithImages($shop, 2);
        $shop->creditWallet()->update(['balance' => 0]);
        $this->auto($shop, $worker)->assertStatus(402);

        // Đủ tiền cho 2 bài: tạo 2 rồi dừng
        $shop->creditWallet()->update(['balance' => 2 * $this->price()]);
        $key = (string) Str::uuid();
        $this->auto($shop, $worker, ['idempotency_key' => $key])->assertCreated()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.stopped', 'insufficient_credit');
        // Gửi lại cùng key: không tạo thêm
        $this->auto($shop, $worker, ['idempotency_key' => $key])->assertCreated()->assertJsonPath('data.created', 2);
        $this->assertSame(2, SocialPost::query()->where('type', 'video')->count());
    }

    public function test_time_with_offset_is_stored_correctly(): void
    {
        $shop = $this->creator(credit: 100000);
        $this->template($shop, 1);
        $this->productWithImages($shop, 1);
        [$worker] = $this->worker($shop);

        $id = $this->auto($shop, $worker, ['count' => 1, 'first_at' => '2030-01-01T09:00:00+07:00'])->json('data.post_ids.0');

        $this->assertSame('2030-01-01 02:00:00', SocialPost::query()->findOrFail($id)->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->getJson("/api/v1/social/posts/{$id}")->assertJsonPath('data.video.scheduled_at', '2030-01-01T02:00:00.000000Z');
    }

    public function test_worker_claims_only_due_posts_then_publishes(): void
    {
        $shop = $this->creator(credit: 100000);
        $this->template($shop, 2);
        $this->productWithImages($shop, 2);
        [$worker, $token] = $this->worker($shop);
        [, $otherToken] = $this->worker($this->creator());
        $id = $this->auto($shop, $worker, ['count' => 1])->json('data.post_ids.0');

        // Chưa tới giờ
        $this->nextJob($token)->assertNoContent();

        $this->travel(2)->hours();
        $this->nextJob($otherToken)->assertNoContent();
        $job = $this->nextJob($token)->assertOk()
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.request.platforms', ['tiktok'])
            ->assertJsonPath('data.request.post_id', $id)
            ->assertJsonPath('data.request.title', 'Video mới')
            ->assertJsonPath('data.request.template.image_count', 2)
            ->assertJsonCount(2, 'data.request.images')
            ->json('data');
        $this->getJson("/api/v1/social/posts/{$id}")->assertJsonPath('data.status', 'publishing');
        $this->nextJob($token)->assertNoContent();

        // Worker đã nhận: không sửa / xoá được
        $this->actingAs($shop)->deleteJson("/api/v1/social/posts/{$id}")->assertStatus(409);

        $this->submit($token, $job['id'], ['status' => 'completed', 'post_url' => 'https://www.tiktok.com/@shop/video/2'])->assertOk();
        $this->actingAs($shop)->getJson("/api/v1/social/posts/{$id}")
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.platforms.tiktok.status', 'published');
    }

    public function test_auto_posts_to_chosen_platforms_in_one_job(): void
    {
        $shop = $this->creator(credit: 100000);
        $this->template($shop, 1);
        $this->productWithImages($shop, 1);
        [$worker, $token] = $this->worker($shop);
        $this->auto($shop, $worker, ['platforms' => ['youtube']])->assertStatus(422)->assertJsonValidationErrors('platforms.0');
        $id = $this->auto($shop, $worker, [
            'count' => 1, 'platforms' => ['tiktok', 'facebook'], 'first_at' => now()->subMinute()->toIso8601String(),
        ])->json('data.post_ids.0');

        $job = $this->nextJob($token)->assertOk()
            ->assertJsonPath('data.request.platforms', ['tiktok', 'facebook'])
            ->assertJsonMissingPath('data.request.account')
            ->json('data');
        $this->submit($token, $job['id'])->assertOk();
        $this->actingAs($shop)->getJson("/api/v1/social/posts/{$id}")
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.platforms.tiktok.status', 'published')
            ->assertJsonPath('data.platforms.facebook.status', 'published');
    }

    public function test_manual_create_one_post_with_chosen_template_product_images(): void
    {
        $shop = $this->creator(credit: 100000);
        $template = $this->template($shop, 2, 'Thủ công');
        $off = $this->template($shop, 2, 'Đã tắt');
        $off->update(['is_enabled' => false]);
        $product = $this->productWithImages($shop, 3);
        [$worker, $token] = $this->worker($shop);
        $images = array_map(
            fn ($i) => ['generation_id' => $i['generation_id'], 'output_id' => $i['output_id']],
            $this->actingAs($shop)->getJson("/api/v1/social/video-posts/images?product_id={$product->id}")->json('data'),
        );
        $body = [
            'worker_id' => $worker->id,
            'platforms' => ['tiktok'],
            'video_template_id' => $template->id,
            'product_id' => $product->id,
            'images' => array_slice($images, 0, 2),
            'scheduled_at' => now()->subMinute()->toIso8601String(),
            'idempotency_key' => (string) Str::uuid(),
        ];

        // Sai số ảnh / template đã tắt / thiếu worker
        $this->postJson('/api/v1/social/video-posts', ['images' => $images] + $body)->assertStatus(422)->assertJsonValidationErrors('images');
        $this->postJson('/api/v1/social/video-posts', ['video_template_id' => $off->id] + $body)->assertStatus(422)->assertJsonValidationErrors('video_template_id');
        $this->postJson('/api/v1/social/video-posts', ['worker_id' => null] + $body)->assertStatus(422)->assertJsonValidationErrors('worker_id');
        $this->postJson('/api/v1/social/video-posts', ['platforms' => []] + $body)->assertStatus(422)->assertJsonValidationErrors('platforms');

        $balance = $this->balance($shop);
        $id = $this->postJson('/api/v1/social/video-posts', $body)->assertCreated()
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.title', 'Video mới')
            ->assertJsonPath('data.video.template.id', $template->id)
            ->assertJsonPath('data.video.product.id', $product->id)
            ->assertJsonPath('data.image_count', 2)
            ->json('data.id');
        $this->assertSame($balance - $this->price(), $this->balance($shop));
        // Gửi lại cùng key: không tạo thêm, không trừ thêm
        $this->postJson('/api/v1/social/video-posts', $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame($balance - $this->price(), $this->balance($shop));

        $this->nextJob($token)->assertOk()->assertJsonPath('data.request.post_id', $id)->assertJsonCount(2, 'data.request.images');

        // Hết tiền: không tạo
        $shop->creditWallet()->update(['balance' => 0]);
        $this->actingAs($shop)->postJson('/api/v1/social/video-posts', ['idempotency_key' => (string) Str::uuid()] + $body)->assertStatus(402);
        $this->assertSame(1, SocialPost::query()->where('type', 'video')->count());
    }

    public function test_failed_post_can_be_edited_and_retried(): void
    {
        $shop = $this->creator(credit: 100000);
        $first = $this->template($shop, 2, 'A');
        $second = $this->template($shop, 1, 'B');
        $product = $this->productWithImages($shop, 3);
        [$worker, $token] = $this->worker($shop);
        $id = $this->auto($shop, $worker, ['count' => 1, 'first_at' => now()->subMinute()->toIso8601String()])->json('data.post_ids.0');
        $post = SocialPost::query()->findOrFail($id);
        $template = $post->video_template_id === $first->id ? $second : $first;

        $job = $this->nextJob($token)->json('data');
        $this->submit($token, $job['id'], ['status' => 'failed', 'error_code' => 'RENDER_FAILED'])->assertOk();
        $this->actingAs($shop)->getJson("/api/v1/social/posts/{$id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.last_error.code', SocialPost::PUBLISH_FAILED);

        // Sửa: số ảnh phải bằng số ảnh template
        $images = $this->actingAs($shop)->getJson("/api/v1/social/video-posts/images?product_id={$product->id}")->assertOk()->json('data');
        $body = [
            'worker_id' => $worker->id,
            'platforms' => ['tiktok', 'instagram'],
            'video_template_id' => $template->id,
            'product_id' => $product->id,
            'images' => array_map(fn ($i) => ['generation_id' => $i['generation_id'], 'output_id' => $i['output_id']], $images),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'title' => 'Sửa tay',
            'hashtags' => ['giay'],
        ];
        $body['images'] = array_slice($body['images'], 0, $template->image_count + 1);
        $this->putJson("/api/v1/social/video-posts/{$id}", $body)->assertStatus(422)->assertJsonValidationErrors('images');
        $body['images'] = array_slice($body['images'], 0, $template->image_count);
        $this->putJson("/api/v1/social/video-posts/{$id}", $body)->assertOk()
            ->assertJsonPath('data.title', 'Sửa tay')
            ->assertJsonPath('data.hashtags', ['giay'])
            ->assertJsonPath('data.video.template.id', $template->id)
            ->assertJsonPath('data.image_count', $template->image_count)
            ->assertJsonPath('data.selected_platforms', ['tiktok', 'instagram'])
            ->assertJsonPath('data.status', 'pending_review');

        // Đăng lại: về chờ tới giờ (ngày mai)
        $this->postJson("/api/v1/social/video-posts/{$id}/retry")->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.last_error', null);
        $this->nextJob($token)->assertNoContent();
        $this->travel(2)->days();
        $this->nextJob($token)->assertOk()
            ->assertJsonPath('data.request.title', 'Sửa tay')
            ->assertJsonPath('data.request.platforms', ['tiktok', 'instagram']);
    }

    public function test_recreate_while_worker_silent_and_old_result_rejected(): void
    {
        $shop = $this->creator(credit: 100000);
        $this->template($shop, 1);
        $this->productWithImages($shop, 1);
        [$worker, $token] = $this->worker($shop);
        $id = $this->auto($shop, $worker, ['count' => 1, 'first_at' => now()->subMinute()->toIso8601String()])->json('data.post_ids.0');
        $old = $this->nextJob($token)->json('data.id');

        // Không hết hạn: sweep không đụng tới
        $this->travel(3)->hours();
        $this->artisan('social:sweep')->assertSuccessful();
        $this->actingAs($shop)->getJson("/api/v1/social/posts/{$id}")->assertJsonPath('data.status', 'publishing');

        $this->postJson("/api/v1/social/video-posts/{$id}/retry")->assertOk()->assertJsonPath('data.status', 'queued');
        $this->submit($token, $old)->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $new = $this->nextJob($token)->assertOk()->json('data.id');
        $this->assertNotSame($old, $new);
    }

    public function test_queued_post_editable_and_deletable_and_rules(): void
    {
        $shop = $this->creator(credit: 100000);
        $this->template($shop, 1);
        $this->productWithImages($shop, 1);
        [$worker] = $this->worker($shop);
        [, $unassignedToken] = $this->worker($shop, assigned: false);
        $id = $this->auto($shop, $worker, ['count' => 1, 'first_at' => now()->subMinute()->toIso8601String()])->json('data.post_ids.0');

        // Chưa lỗi / worker chưa nhận thì không "đăng lại"
        $this->postJson("/api/v1/social/video-posts/{$id}/retry")->assertStatus(409);

        // Worker không gắn shop: không nhận được bài nào
        $this->nextJob($unassignedToken)->assertNoContent();

        // Worker không gắn cho shop
        [$foreign] = $this->worker($this->creator());
        $this->actingAs($shop)->postJson('/api/v1/social/video-posts/auto', [
            'worker_id' => $foreign->id, 'count' => 1, 'first_at' => now()->toIso8601String(), 'gap_min' => 50, 'gap_max' => 40,
            'idempotency_key' => 'k1',
        ])->assertStatus(422)->assertJsonValidationErrors('gap_max');
        $this->auto($shop, $foreign)->assertStatus(422)->assertJsonValidationErrors('worker_id');

        $this->deleteJson("/api/v1/social/posts/{$id}")->assertNoContent();

        // Chưa mở cửa hàng
        $this->actingAs($this->buyer())->postJson('/api/v1/social/video-posts/auto', [])->assertForbidden();
    }
}
