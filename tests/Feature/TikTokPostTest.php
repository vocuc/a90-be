<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Enums\TransactionStatus;
use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\Hashtag;
use App\Models\TikTokContentJob;
use App\Models\TikTokPost;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTikTok;
use Tests\TestCase;

class TikTokPostTest extends TestCase
{
    use InteractsWithTikTok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTikTok();
        // Phần lớn test đi qua worker viết nội dung; chế độ tự nhập (mặc định) có test riêng
        config(['ai90.tiktok.ai_content' => true]);
    }

    // ---- Helpers ----

    /** Ảnh đã tạo xong của người dùng: [generation_id, output_id]. */
    private function completedImage(User $user, ?AiCreative $creative = null): array
    {
        $creative ??= $this->publishedCreative($this->creator());
        $response = $this->generate($user, $creative)->assertStatus(202);
        $this->runWorker([null]);
        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));

        return ['generation_id' => $generation->id, 'output_id' => $generation->outputs[0]->id];
    }

    private function fromImages(User $user, array $images, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/tiktok/posts/from-images', array_merge([
            'images' => $images,
            'idempotency_key' => (string) Str::uuid(),
        ], $extra));
    }

    /** @param  AiCreative[]  $creatives */
    private function fromCreatives(User $user, array $creatives, array $extra = []): TestResponse
    {
        $items = array_map(fn (AiCreative $creative) => [
            'creative_id' => $creative->id,
            'inputs' => ['product_image' => UploadedFile::fake()->image('product.png', 64, 64), 'aspectRatio' => '1:1'],
        ], $creatives);

        return $this->actingAs($user)->post('/api/v1/tiktok/posts/from-creatives', array_merge([
            'items' => $items,
            'idempotency_key' => (string) Str::uuid(),
        ], $extra), ['Accept' => 'application/json']);
    }

    private function claimContentJob(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/content-jobs');
    }

    private function submitContent(string $token, int $jobId, array $body): TestResponse
    {
        return $this->withToken($token)->postJson("/api/v1/content-jobs/{$jobId}", $body);
    }

    /** Worker viết nội dung xong -> bài sang pending_review. */
    private function writeContent(array $body = []): void
    {
        $token = $this->workerToken('writer-'.Str::random(6));
        $jobId = $this->claimContentJob($token)->assertOk()->json('data.id');
        $this->submitContent($token, $jobId, array_merge([
            'status' => 'completed',
            'title' => 'Lột xác ảnh sản phẩm',
            'content' => 'Ảnh đẹp mỗi ngày.',
            'hashtags' => ['a51', 'anhsanpham'],
        ], $body))->assertOk();
    }

    /** Bài đang chờ duyệt từ 1 ảnh có sẵn. */
    private function pendingPost(User $user): TikTokPost
    {
        $id = $this->fromImages($user, [$this->completedImage($user)])->assertCreated()->json('data.id');
        $this->writeContent();

        return TikTokPost::query()->findOrFail($id);
    }

    private function publishBody(TikTokPost $post, array $extra = []): array
    {
        return array_merge([
            'title' => 'Tiêu đề đã sửa',
            'content' => 'Nội dung đã sửa',
            'hashtags' => ['a51', '#ChânDung'],
            'images' => $post->images()->get()->map(fn ($image) => ['image_id' => $image->id])->all(),
        ], $extra);
    }

    private function fakePublishApis($init = null): void
    {
        $this->fakeTikTok([
            '/post/publish/content/init/' => $init ?? Http::response(['data' => ['publish_id' => 'p_pub_1'], 'error' => ['code' => 'ok']]),
        ]);
    }

    // ---- Tạo bài từ ảnh đã có ----

    public function test_post_from_existing_images_waits_for_content_then_goes_to_review(): void
    {
        $user = $this->buyer(credit: 5000);
        $first = $this->completedImage($user);
        $second = $this->completedImage($user);
        $balance = $this->balance($user);

        $response = $this->fromImages($user, [$second, $first])
            ->assertCreated()
            ->assertJsonPath('data.status', 'generating')
            ->assertJsonPath('data.source', 'existing')
            ->assertJsonPath('data.image_count', 2)
            ->assertJsonPath('data.images.0.output_id', $second['output_id'])
            ->assertJsonPath('data.images.0.position', 1)
            ->assertJsonPath('data.images.0.status', 'completed')
            ->assertJsonPath('data.images.0.creative.title', 'Ảnh sản phẩm studio');
        $this->assertNotNull($response->json('data.cover_url'));
        $this->assertSame($balance, $this->balance($user), 'Dùng ảnh có sẵn không tính phí');

        // Worker nhận job nội dung: tên mẫu, ngành hàng, hashtag và link ảnh
        $token = $this->workerToken('writer');
        $job = $this->claimContentJob($token)->assertOk()->json('data');
        $this->assertSame('vi', $job['request']['language']);
        $this->assertSame(90, $job['request']['limits']['title_max']);
        $this->assertSame('Ảnh sản phẩm studio', $job['request']['creatives'][0]['title']);
        $this->assertCount(2, $job['request']['images']);
        $this->claimContentJob($token)->assertNoContent();

        // Hashtag lẫn trong nội dung được tách ra, hashtag sai quy tắc / trùng bị bỏ
        $this->submitContent($token, $job['id'], [
            'status' => 'completed',
            'title' => str_repeat('Tiêu đề dài ', 20),
            'content' => "Ảnh đẹp mỗi ngày #xuhuong\nThử ngay!",
            'hashtags' => ['#a51', 'A51', 'có khoảng trắng', 'anhAI'],
        ])->assertOk();

        $post = $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.hashtags', ['a51', 'anhAI', 'xuhuong'])
            ->assertJsonPath('data.content', "Ảnh đẹp mỗi ngày\nThử ngay!")
            ->json('data');
        $this->assertLessThanOrEqual(90, mb_strlen($post['title']));
    }

    public function test_cannot_use_images_of_other_users_or_unfinished_images(): void
    {
        $owner = $this->buyer(credit: 5000);
        $image = $this->completedImage($owner);
        $other = $this->buyer();

        $this->fromImages($other, [$image])->assertStatus(422)->assertJsonValidationErrors('images.0');
        $this->fromImages($owner, [['generation_id' => $image['generation_id'] + 99, 'output_id' => $image['output_id']]])
            ->assertStatus(422);

        $pending = $this->generate($owner, $this->publishedCreative($this->creator()))->json('data');
        $this->fromImages($owner, [['generation_id' => $pending['id'], 'output_id' => Generation::query()->find($pending['id'])->outputs[0]->id]])
            ->assertStatus(422);

        $this->fromImages($owner, array_fill(0, 36, $image))->assertStatus(422)->assertJsonValidationErrors('images');
        $this->assertSame(0, TikTokPost::query()->count());
    }

    public function test_same_idempotency_key_returns_same_post(): void
    {
        $user = $this->buyer(credit: 5000);
        $image = $this->completedImage($user);
        $body = ['images' => [$image], 'idempotency_key' => 'same-key'];

        $first = $this->actingAs($user)->postJson('/api/v1/tiktok/posts/from-images', $body)->assertCreated()->json('data.id');
        $second = $this->actingAs($user)->postJson('/api/v1/tiktok/posts/from-images', $body)->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, TikTokContentJob::query()->count());
    }

    public function test_content_job_falls_back_to_creative_info_after_max_attempts(): void
    {
        $user = $this->buyer(credit: 5000);
        $creative = $this->publishedCreative($this->creator());
        $creative->hashtags()->attach(Hashtag::query()->create(['name' => 'MyPham', 'slug' => 'mypham'])->id, ['sort_order' => 1]);
        $id = $this->fromImages($user, [$this->completedImage($user, $creative)])->json('data.id');
        $token = $this->workerToken('writer');

        foreach (range(1, config('ai90.worker.max_attempts')) as $attempt) {
            $jobId = $this->claimContentJob($token)->assertOk()->json('data.id');
            $this->submitContent($token, $jobId, ['status' => 'failed', 'error_code' => 'QUOTA_EXCEEDED'])->assertOk();
        }

        $this->claimContentJob($token)->assertNoContent();
        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Ảnh sản phẩm studio')
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.hashtags', ['MyPham']);
    }

    public function test_expired_content_lease_is_requeued_by_sweep(): void
    {
        $user = $this->buyer(credit: 5000);
        $this->fromImages($user, [$this->completedImage($user)]);
        $token = $this->workerToken('writer');
        $jobId = $this->claimContentJob($token)->json('data.id');

        $this->travel(config('ai90.worker.content_lease_minutes') + 1)->minutes();
        $this->artisan('tiktok:sweep')->assertSuccessful();

        $this->assertSame(OutputStatus::Queued, TikTokContentJob::query()->find($jobId)->status);
        $this->submitContent($token, $jobId, ['status' => 'completed', 'title' => 'x'])->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
    }

    // ---- Không dùng worker viết nội dung (mặc định) ----

    public function test_without_ai_content_post_goes_to_review_with_manual_content(): void
    {
        config(['ai90.tiktok.ai_content' => false]);
        $user = $this->buyer(credit: 5000);

        $this->fromImages($user, [$this->completedImage($user)], [
            'title' => 'Tiêu đề tự nhập',
            'content' => 'Nội dung tự nhập',
            'hashtags' => ['#a51', 'A51', 'xuhuong'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Tiêu đề tự nhập')
            ->assertJsonPath('data.content', 'Nội dung tự nhập')
            ->assertJsonPath('data.hashtags', ['a51', 'xuhuong']);

        $this->assertSame(0, TikTokContentJob::query()->count());
    }

    public function test_without_ai_content_and_no_input_uses_creative_defaults(): void
    {
        config(['ai90.tiktok.ai_content' => false]);
        $user = $this->buyer(credit: 5000);
        $creative = $this->publishedCreative($this->creator());
        $creative->hashtags()->attach(Hashtag::query()->create(['name' => 'MyPham', 'slug' => 'mypham'])->id, ['sort_order' => 1]);

        $this->fromImages($user, [$this->completedImage($user, $creative)])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Ảnh sản phẩm studio')
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.hashtags', ['MyPham']);
        $this->assertSame(0, TikTokContentJob::query()->count());
    }

    public function test_without_ai_content_new_images_go_to_review_when_generated(): void
    {
        config(['ai90.tiktok.ai_content' => false]);
        $user = $this->buyer(credit: 5000);

        $id = $this->fromCreatives($user, [$this->publishedCreative($this->creator())], ['title' => 'Bài mới'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'generating')
            ->json('data.id');

        $this->runWorker([null]);

        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Bài mới');
        $this->assertSame(0, TikTokContentJob::query()->count());
    }

    public function test_manual_content_skips_worker_even_when_ai_content_enabled(): void
    {
        $user = $this->buyer(credit: 5000);

        $this->fromImages($user, [$this->completedImage($user)], ['title' => 'Tự viết'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review');
        $this->assertSame(0, TikTokContentJob::query()->count());
    }

    public function test_create_validates_manual_content(): void
    {
        config(['ai90.tiktok.ai_content' => false]);
        $user = $this->buyer(credit: 5000);

        $this->fromImages($user, [$this->completedImage($user)], [
            'title' => str_repeat('a', 91),
            'hashtags' => array_map(fn ($i) => "tag{$i}", range(1, 11)),
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'hashtags']);
        $this->assertSame(0, TikTokPost::query()->count());
    }

    // ---- Tạo bài bằng ảnh mới ----

    public function test_post_from_creatives_charges_once_and_writes_content_after_all_images(): void
    {
        $creatorA = $this->creator();
        $a = $this->publishedCreative($creatorA, price: 1000);
        $b = $this->publishedCreative($this->creator(), price: 2500);
        $user = $this->buyer(credit: 10000);

        $response = $this->fromCreatives($user, [$a, $b])
            ->assertCreated()
            ->assertJsonPath('data.status', 'generating')
            ->assertJsonPath('data.source', 'new')
            ->assertJsonPath('data.image_count', 2)
            ->assertJsonPath('data.images.0.status', 'queued')
            ->assertJsonPath('data.images.1.creative.price_per_image', 2500)
            ->assertJsonPath('data.cover_url', null);
        $id = $response->json('data.id');

        $this->assertSame(6500, $this->balance($user));
        $this->assertSame(2, Generation::query()->where('user_id', $user->id)->where('quantity', 1)->count());

        // Chưa xong ảnh: chưa có job nội dung
        $this->runWorker([null]);
        $this->assertSame(0, TikTokContentJob::query()->count());

        // Ảnh thứ 2 lỗi: hoàn tiền ảnh đó, bài vẫn sang bước viết nội dung
        $this->runWorker(['CONTENT_BLOCKED']);
        $this->assertSame(1, TikTokContentJob::query()->count());
        $this->assertSame(9000, $this->balance($user));
        $this->assertLedgerMatchesBalance($user);

        $this->writeContent();
        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.images.0.status', 'completed')
            ->assertJsonPath('data.images.1.status', 'failed')
            ->assertJsonPath('data.images.1.image_url', null)
            ->assertJsonPath('data.images.1.error_message', 'Ảnh hoặc nội dung vi phạm chính sách an toàn của AI.');
    }

    public function test_insufficient_credit_for_total_creates_nothing(): void
    {
        $user = $this->buyer(credit: 1500);
        $creative = $this->publishedCreative($this->creator(), price: 1000);

        $this->fromCreatives($user, [$creative, $creative])
            ->assertStatus(402)
            ->assertJsonPath('code', 'INSUFFICIENT_CREDIT')
            ->assertJsonPath('context.required', 2000);

        $this->assertSame(0, TikTokPost::query()->count());
        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(1500, $this->balance($user));
    }

    public function test_inputs_are_validated_per_item(): void
    {
        $user = $this->buyer(credit: 5000);
        $creative = $this->publishedCreative($this->creator());

        $this->actingAs($user)->post('/api/v1/tiktok/posts/from-creatives', [
            'items' => [
                ['creative_id' => $creative->id, 'inputs' => ['product_image' => UploadedFile::fake()->image('a.png'), 'aspectRatio' => '1:1']],
                ['creative_id' => $creative->id, 'inputs' => ['aspectRatio' => '1:1']],
            ],
            'idempotency_key' => 'k1',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.inputs.product_image')
            ->assertJsonMissingValidationErrors('items.0.inputs.product_image');

        $this->actingAs($user)->postJson('/api/v1/tiktok/posts/from-creatives', [
            'items' => [['creative_id' => 999999]], 'idempotency_key' => 'k2',
        ])->assertStatus(404)->assertJsonPath('code', 'CREATIVE_NOT_AVAILABLE');

        $this->assertSame(5000, $this->balance($user));
    }

    // ---- Tạo lại / sửa nguyên liệu ----

    public function test_regenerate_charges_and_replaces_image_in_same_position(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $image = $post->images()->first();
        $balance = $this->balance($user);

        $new = $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/images/{$image->id}/regenerate", [
            'idempotency_key' => 'regen-1',
        ])->assertStatus(202)
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.status', 'queued')
            ->json('data');

        $this->assertNotSame($image->output_id, $new['output_id']);
        $this->assertSame($balance - 1000, $this->balance($user));
        $this->assertSame($image->generation_id, Generation::query()->find($new['generation_id'])->parent_generation_id);

        // Đang tạo lại: chưa đăng được
        $this->connectTikTok($user);
        $this->fakePublishApis();
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))
            ->assertStatus(409)->assertJsonPath('code', 'IMAGES_NOT_READY');

        $this->runWorker([null]);
        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.images.0.status', 'completed')
            ->assertJsonPath('data.images.0.output_id', $new['output_id']);
    }

    public function test_edit_inputs_keeps_old_file_and_changes_aspect_ratio(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $image = $post->images()->first();
        $oldGeneration = Generation::query()->find($image->generation_id);

        $inputs = $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$post->id}/images/{$image->id}/inputs")
            ->assertOk()
            ->assertJsonPath('data.creative.title', 'Ảnh sản phẩm studio')
            ->assertJsonPath('data.inputs.aspectRatio', '1:1')
            ->json('data.inputs');
        $fileId = $inputs['product_image'][0]['file_id'];
        $this->assertStringStartsWith('f_', $fileId);

        $new = $this->actingAs($user)->post("/api/v1/tiktok/posts/{$post->id}/images/{$image->id}/regenerate", [
            'inputs' => ['aspectRatio' => '4:5'],
            'keep_files' => ['product_image' => [$fileId]],
            'idempotency_key' => 'edit-1',
        ], ['Accept' => 'application/json'])->assertStatus(202)->json('data');

        $generation = Generation::query()->find($new['generation_id']);
        $this->assertSame('4:5', $generation->aspect_ratio);
        $this->assertSame($oldGeneration->input_paths['product_image'], $generation->input_paths['product_image']);

        // file_id sai -> lỗi theo trường
        $this->actingAs($user)->post("/api/v1/tiktok/posts/{$post->id}/images/{$image->id}/regenerate", [
            'inputs' => ['aspectRatio' => '1:1'],
            'keep_files' => ['product_image' => ['f_wrong']],
            'idempotency_key' => 'edit-2',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('keep_files.product_image');
    }

    public function test_image_inputs_null_when_input_file_was_deleted(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $image = $post->images()->first();
        Storage::disk('media')->delete(Generation::query()->find($image->generation_id)->input_paths['product_image']);

        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$post->id}/images/{$image->id}/inputs")
            ->assertOk()
            ->assertJsonPath('data.inputs', null);
    }

    public function test_regenerate_only_while_pending_review(): void
    {
        $user = $this->buyer(credit: 5000);
        $id = $this->fromImages($user, [$this->completedImage($user)])->json('data.id');
        $image = TikTokPost::query()->find($id)->images()->first();

        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$id}/images/{$image->id}/regenerate", ['idempotency_key' => 'r'])
            ->assertStatus(409)->assertJsonPath('code', 'POST_NOT_EDITABLE');
    }

    // ---- Đăng ----

    public function test_publish_uploads_photos_to_inbox_and_webhooks_track_until_published(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $extra = $this->completedImage($user);
        $this->connectTikTok($user);
        $this->fakePublishApis();

        $body = $this->publishBody($post);
        $body['images'][] = $extra; // ảnh có sẵn thêm ở bước duyệt
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'publishing')
            ->assertJsonPath('data.title', 'Tiêu đề đã sửa')
            ->assertJsonPath('data.hashtags', ['a51', 'ChânDung'])
            ->assertJsonPath('data.image_count', 2)
            ->assertJsonPath('data.images.1.output_id', $extra['output_id'])
            ->assertJsonPath('data.tiktok_publish_id', 'p_pub_1');

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/post/publish/content/init/')) {
                return false;
            }
            $urls = $r['source_info']['photo_images'];

            return $r['post_mode'] === 'MEDIA_UPLOAD' && $r['media_type'] === 'PHOTO'
                && $r['source_info']['source'] === 'PULL_FROM_URL' && $r['source_info']['photo_cover_index'] === 0
                && count($urls) === 2 && collect($urls)->every(fn ($u) => preg_match('#^https://media\.a51\.test/tiktok/.+\.jpg$#', $u))
                && $r['post_info'] === ['title' => 'Tiêu đề đã sửa', 'description' => "Nội dung đã sửa\n#a51 #ChânDung"];
        });

        // PNG được chuyển sang JPG trên disk tên miền đã xác minh
        $files = Storage::disk('tiktok_media')->allFiles('tiktok');
        $this->assertCount(2, $files);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('tiktok_media')->get($files[0])));

        $this->sendWebhook('post.publish.inbox_delivered', ['publish_id' => 'p_pub_1'])->assertOk();
        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$post->id}")->assertJsonPath('data.status', 'inbox_delivered');
        $this->actingAs($user)->deleteJson("/api/v1/tiktok/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');

        // Người dùng đăng trong app TikTok
        $this->sendWebhook('post.publish.complete', ['publish_id' => 'p_pub_1', 'publish_type' => 'INBOX_SHARE'])->assertOk();
        $this->sendWebhook('post.publish.publicly_available', ['publish_id' => 'p_pub_1', 'post_id' => '7300000000000000001'])->assertOk();

        $post->refresh();
        $this->assertSame(TikTokPostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertSame('https://www.tiktok.com/@a51.vn/photo/7300000000000000001', $post->tiktok_post_url);
        $this->actingAs($user)->deleteJson("/api/v1/tiktok/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');
    }

    public function test_publish_failed_webhook_returns_post_to_review_with_error(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $this->connectTikTok($user);
        $this->fakePublishApis();
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))->assertOk();

        $this->sendWebhook('post.publish.failed', ['publish_id' => 'p_pub_1', 'reason' => 'photo_pull_failed'])->assertOk();

        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.last_error.code', 'photo_pull_failed')
            ->assertJsonPath('data.last_error.message', 'TikTok không tải được ảnh, vui lòng đăng lại.');

        // publish_id lạ: trả 404 để TikTok gửi lại
        $this->sendWebhook('post.publish.complete', ['publish_id' => 'unknown'])->assertNotFound();
    }

    public function test_draft_cancelled_in_tiktok_returns_post_to_review(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $this->connectTikTok($user);
        $this->fakePublishApis();
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))->assertOk();

        $this->sendWebhook('post.publish.inbox_delivered', ['publish_id' => 'p_pub_1'])->assertOk();
        $this->sendWebhook('post.publish.failed', ['publish_id' => 'p_pub_1', 'reason' => 'publish_cancelled'])->assertOk();

        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.last_error.code', 'publish_cancelled');
    }

    public function test_publish_requires_connected_account(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);

        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))
            ->assertStatus(409)
            ->assertJsonPath('code', 'TIKTOK_NOT_CONNECTED');
        $this->assertSame(TikTokPostStatus::PendingReview, $post->fresh()->status);
    }

    public function test_tiktok_rejecting_init_returns_friendly_error_and_post_stays_editable(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $this->connectTikTok($user);
        $this->fakePublishApis(init: Http::response(self::tiktokError('spam_risk_too_many_posts'), 403));

        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))
            ->assertStatus(429)
            ->assertJsonPath('code', 'TIKTOK_PUBLISH_REJECTED')
            ->assertJsonPath('message', 'Tài khoản đã đạt giới hạn đăng bài trong ngày, thử lại sau.');

        $post->refresh();
        $this->assertSame(TikTokPostStatus::PendingReview, $post->status);
        $this->assertSame('TIKTOK_PUBLISH_REJECTED', $post->last_error['code']);
        $this->assertSame('Tiêu đề đã sửa', $post->title, 'Chỉnh sửa vẫn được lưu');
    }

    public function test_publish_validates_limits(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $this->connectTikTok($user);

        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post, [
            'title' => str_repeat('a', 91),
            'hashtags' => array_map(fn ($i) => "tag{$i}", range(1, 11)),
            'images' => [],
        ]))->assertStatus(422)->assertJsonValidationErrors(['title', 'hashtags', 'images']);
    }

    // ---- Xem / xoá ----

    public function test_list_and_show_only_own_posts(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $other = $this->buyer();

        $this->actingAs($user)->getJson('/api/v1/tiktok/posts?status=pending_review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->getJson('/api/v1/tiktok/posts?status=published')->assertJsonCount(0, 'data');

        $this->actingAs($other)->getJson('/api/v1/tiktok/posts')->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson("/api/v1/tiktok/posts/{$post->id}")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/tiktok/posts/{$post->id}")->assertNotFound();
    }

    public function test_delete_only_when_pending_review(): void
    {
        $user = $this->buyer(credit: 5000);
        $generating = $this->fromImages($user, [$this->completedImage($user)])->json('data.id');

        $this->actingAs($user)->deleteJson("/api/v1/tiktok/posts/{$generating}")
            ->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');

        $this->writeContent();
        $this->actingAs($user)->deleteJson("/api/v1/tiktok/posts/{$generating}")->assertNoContent();
        $this->assertSame(0, TikTokPost::query()->count());
        // Ảnh vẫn còn trong lịch sử tạo ảnh
        $this->assertSame(1, Generation::query()->where('user_id', $user->id)->count());
        $this->assertTrue(Transaction::query()->where('user_id', $user->id)->get()->every(fn ($tx) => $tx->status === TransactionStatus::Completed));
    }
}
