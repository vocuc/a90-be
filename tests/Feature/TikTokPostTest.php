<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Enums\TransactionStatus;
use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\Hashtag;
use App\Models\TikTokAccount;
use App\Models\TikTokContentJob;
use App\Models\TikTokPost;
use App\Models\TikTokPublishJob;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TikTokPostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    // ---- Đăng (worker lưu nháp) ----

    /** Admin gắn tài khoản A51 với một worker: trả token của worker. */
    private function publisherToken(User $user, string $name = 'tiktok-worker', bool $active = true): string
    {
        $token = $this->workerToken($name);
        TikTokAccount::query()->create([
            'user_id' => $user->id,
            'name' => 'A51 Official',
            'username' => 'a51.vn',
            'worker_id' => Worker::query()->where('name', $name)->value('id'),
            'is_active' => $active,
        ]);

        return $token;
    }

    private function claimPublishJob(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/publish-jobs');
    }

    private function submitPublish(string $token, int $jobId, array $body = ['status' => 'completed']): TestResponse
    {
        return $this->withToken($token)->postJson("/api/v1/publish-jobs/{$jobId}", $body);
    }

    public function test_publish_queues_job_and_worker_saves_draft(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $extra = $this->completedImage($user);

        $body = $this->publishBody($post, ['title' => '']); // tiêu đề không bắt buộc
        $body['images'][] = $extra; // ảnh có sẵn thêm ở bước duyệt
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.title', '')
            ->assertJsonPath('data.hashtags', ['a51', 'ChânDung'])
            ->assertJsonPath('data.image_count', 2)
            ->assertJsonPath('data.images.1.output_id', $extra['output_id']);

        // Đã bấm đăng: khoá
        $this->actingAs($user)->deleteJson("/api/v1/tiktok/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $body)->assertStatus(409)->assertJsonPath('code', 'POST_NOT_EDITABLE');
        $this->assertSame(1, TikTokPublishJob::query()->count());

        // Worker chưa gắn tài khoản A51 không nhận được job; worker của tài khoản A51 khác không thấy job này
        $this->claimPublishJob($this->workerToken('no-account'))->assertStatus(409)->assertJsonPath('code', 'TIKTOK_ACCOUNT_NOT_ASSIGNED');
        $this->claimPublishJob($this->publisherToken($this->buyer(), 'other-worker'))->assertNoContent();

        $token = $this->publisherToken($user);
        $job = $this->claimPublishJob($token)->assertOk()->json('data');
        $this->assertSame('tiktok_publish', $job['type']);
        $this->assertSame($post->id, $job['request']['post_id']);
        $this->assertSame("Nội dung đã sửa\n#a51 #ChânDung", $job['request']['description']);
        $this->assertCount(2, $job['request']['images']);
        $this->assertSame('a51.vn', $job['request']['account']['username']);
        $this->claimPublishJob($token)->assertNoContent();

        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$post->id}")->assertJsonPath('data.status', 'publishing');

        $this->submitPublish($token, $job['id'])->assertOk()->assertJsonPath('data.status', 'completed');
        $post->refresh();
        $this->assertSame(TikTokPostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertSame(TikTokAccount::query()->where('user_id', $user->id)->value('id'), $post->tiktok_account_id);
        $this->actingAs($user)->deleteJson("/api/v1/tiktok/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');
    }

    public function test_worker_failure_returns_post_to_review_with_generic_error(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $token = $this->publisherToken($user);
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))->assertOk();

        $jobId = $this->claimPublishJob($token)->json('data.id');
        $this->submitPublish($token, $jobId, ['status' => 'failed', 'error_code' => 'TIKTOK_CAPTCHA', 'error_message' => 'Captcha'])->assertOk();

        $this->actingAs($user)->getJson("/api/v1/tiktok/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Tiêu đề đã sửa')
            ->assertJsonPath('data.last_error.code', 'TIKTOK_PUBLISH_FAILED')
            ->assertJsonPath('data.last_error.message', 'Đăng thất bại, vui lòng thử lại.');
        // Mã lỗi chi tiết chỉ lưu ở job; không giao lại cho worker khác
        $this->assertSame('TIKTOK_CAPTCHA', TikTokPublishJob::query()->find($jobId)->error_code);
        $this->claimPublishJob($token)->assertNoContent();

        // Người dùng bấm đăng lại: job mới
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))
            ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.last_error', null);
        $this->assertSame(2, TikTokPublishJob::query()->count());
    }

    public function test_expired_publish_lease_fails_post_without_requeue(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $token = $this->publisherToken($user);
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))->assertOk();
        $jobId = $this->claimPublishJob($token)->json('data.id');

        $this->travel(config('ai90.worker.publish_lease_minutes') + 1)->minutes();
        $this->artisan('tiktok:sweep')->assertSuccessful();

        $this->assertSame(TikTokPostStatus::PendingReview, $post->fresh()->status);
        $this->assertSame('WORKER_TIMEOUT', TikTokPublishJob::query()->find($jobId)->error_code);
        $this->submitPublish($token, $jobId)->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $this->claimPublishJob($token)->assertNoContent();
    }

    public function test_queued_job_waits_until_an_active_account_worker_claims_it(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $token = $this->publisherToken($user, active: false);
        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post))->assertOk();

        $this->claimPublishJob($token)->assertStatus(409)->assertJsonPath('code', 'TIKTOK_ACCOUNT_NOT_ASSIGNED');
        $this->travel(2)->days();
        $this->artisan('tiktok:sweep')->assertSuccessful();
        $this->assertSame(TikTokPostStatus::Queued, $post->fresh()->status, 'Job chờ không hết hạn');

        TikTokAccount::query()->update(['is_active' => true]);
        $this->claimPublishJob($token)->assertOk();
    }

    public function test_publish_validates_limits(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);

        $this->actingAs($user)->postJson("/api/v1/tiktok/posts/{$post->id}/publish", $this->publishBody($post, [
            'title' => str_repeat('a', 91),
            'hashtags' => array_map(fn ($i) => "tag{$i}", range(1, 11)),
            'images' => [],
        ]))->assertStatus(422)->assertJsonValidationErrors(['title', 'hashtags', 'images']);
        $this->assertSame(0, TikTokPublishJob::query()->count());
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
