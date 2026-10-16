<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Enums\TransactionStatus;
use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\Hashtag;
use App\Models\PublishAccount;
use App\Models\SocialContentJob;
use App\Models\SocialPost;
use App\Models\SocialPublishJob;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SocialPostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Phần lớn test đi qua worker viết nội dung; chế độ tự nhập (mặc định) có test riêng
        config(['ai90.social.ai_content' => true]);
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
        return $this->actingAs($user)->postJson('/api/v1/social/posts/from-images', array_merge([
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
    private function pendingPost(User $user): SocialPost
    {
        $id = $this->fromImages($user, [$this->completedImage($user)])->assertCreated()->json('data.id');
        $this->writeContent();

        return SocialPost::query()->findOrFail($id);
    }

    private function publishBody(SocialPost $post, array $extra = []): array
    {
        return array_merge([
            'title' => 'Tiêu đề đã sửa',
            'content' => 'Nội dung đã sửa',
            'hashtags' => ['a51', '#ChânDung'],
            'images' => $post->images()->get()->map(fn ($image) => ['image_id' => $image->id])->all(),
            'platforms' => ['tiktok'],
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

        $post = $this->actingAs($user)->getJson("/api/v1/social/posts/{$response->json('data.id')}")
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
        $this->assertSame(0, SocialPost::query()->count());
    }

    public function test_same_idempotency_key_returns_same_post(): void
    {
        $user = $this->buyer(credit: 5000);
        $image = $this->completedImage($user);
        $body = ['images' => [$image], 'idempotency_key' => 'same-key'];

        $first = $this->actingAs($user)->postJson('/api/v1/social/posts/from-images', $body)->assertCreated()->json('data.id');
        $second = $this->actingAs($user)->postJson('/api/v1/social/posts/from-images', $body)->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, SocialContentJob::query()->count());
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
        $this->actingAs($user)->getJson("/api/v1/social/posts/{$id}")
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
        $this->artisan('social:sweep')->assertSuccessful();

        $this->assertSame(OutputStatus::Queued, SocialContentJob::query()->find($jobId)->status);
        $this->submitContent($token, $jobId, ['status' => 'completed', 'title' => 'x'])->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
    }

    // ---- Không dùng worker viết nội dung (mặc định) ----

    public function test_without_ai_content_post_goes_to_review_with_manual_content(): void
    {
        config(['ai90.social.ai_content' => false]);
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

        $this->assertSame(0, SocialContentJob::query()->count());
    }

    public function test_without_ai_content_and_no_input_uses_creative_defaults(): void
    {
        config(['ai90.social.ai_content' => false]);
        $user = $this->buyer(credit: 5000);
        $creative = $this->publishedCreative($this->creator());
        $creative->hashtags()->attach(Hashtag::query()->create(['name' => 'MyPham', 'slug' => 'mypham'])->id, ['sort_order' => 1]);

        $this->fromImages($user, [$this->completedImage($user, $creative)])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Ảnh sản phẩm studio')
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.hashtags', ['MyPham']);
        $this->assertSame(0, SocialContentJob::query()->count());
    }

    public function test_manual_content_skips_worker_even_when_ai_content_enabled(): void
    {
        $user = $this->buyer(credit: 5000);

        $this->fromImages($user, [$this->completedImage($user)], ['title' => 'Tự viết'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review');
        $this->assertSame(0, SocialContentJob::query()->count());
    }

    public function test_create_validates_manual_content(): void
    {
        config(['ai90.social.ai_content' => false]);
        $user = $this->buyer(credit: 5000);

        $this->fromImages($user, [$this->completedImage($user)], [
            'title' => str_repeat('a', 91),
            'hashtags' => array_map(fn ($i) => "tag{$i}", range(1, 11)),
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'hashtags']);
        $this->assertSame(0, SocialPost::query()->count());
    }

    // ---- Đăng (worker đăng theo từng nền tảng) ----

    /** Admin gắn tài khoản A51 với một worker của một nền tảng: trả token của worker. */
    private function publisherToken(User $user, string $name = 'tiktok-worker', bool $active = true, SocialPlatform $platform = SocialPlatform::Tiktok, ?string $targetUrl = null): string
    {
        $token = $this->workerToken($name);
        PublishAccount::query()->create([
            'user_id' => $user->id,
            'platform' => $platform,
            'name' => 'A51 Official',
            'username' => 'a51.vn',
            'target_url' => $targetUrl,
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
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.platforms.tiktok.status', 'queued')
            ->assertJsonMissingPath('data.platforms.facebook')
            ->assertJsonPath('data.title', '')
            ->assertJsonPath('data.hashtags', ['a51', 'ChânDung'])
            ->assertJsonPath('data.image_count', 2)
            ->assertJsonPath('data.images.1.output_id', $extra['output_id']);

        // Đã bấm đăng: khoá
        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $body)->assertStatus(409)->assertJsonPath('code', 'POST_NOT_EDITABLE');
        $this->assertSame(1, SocialPublishJob::query()->count());

        // Worker chưa gắn tài khoản A51 không nhận được job; worker của tài khoản A51 khác không thấy job này
        $this->claimPublishJob($this->workerToken('no-account'))->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_NOT_ASSIGNED');
        $this->claimPublishJob($this->publisherToken($this->buyer(), 'other-worker'))->assertNoContent();

        $token = $this->publisherToken($user);
        $job = $this->claimPublishJob($token)->assertOk()->json('data');
        $this->assertSame('tiktok_publish', $job['type']);
        $this->assertSame('tiktok', $job['request']['platform']);
        $this->assertSame($post->id, $job['request']['post_id']);
        $this->assertSame("Nội dung đã sửa\n#a51 #ChânDung", $job['request']['description']);
        $this->assertCount(2, $job['request']['images']);
        $this->assertSame('a51.vn', $job['request']['account']['username']);
        $this->claimPublishJob($token)->assertNoContent();

        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'publishing')
            ->assertJsonPath('data.platforms.tiktok.status', 'publishing');

        $this->submitPublish($token, $job['id'])->assertOk()->assertJsonPath('data.status', 'completed');
        $post->refresh();
        $this->assertSame(SocialPostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertSame(PlatformPublishStatus::Published, $post->tiktok_status);
        $this->assertSame(PublishAccount::query()->where('user_id', $user->id)->value('id'), SocialPublishJob::query()->find($job['id'])->publish_account_id);
        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');
    }

    public function test_worker_failure_returns_post_to_review_with_generic_error(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $token = $this->publisherToken($user);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post))->assertOk();

        $jobId = $this->claimPublishJob($token)->json('data.id');
        $this->submitPublish($token, $jobId, ['status' => 'failed', 'error_code' => 'TIKTOK_CAPTCHA', 'error_message' => 'Captcha'])->assertOk();

        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Tiêu đề đã sửa')
            ->assertJsonPath('data.platforms.tiktok.status', 'failed')
            ->assertJsonPath('data.platforms.tiktok.error', 'Đăng thất bại, vui lòng thử lại.')
            ->assertJsonPath('data.last_error.code', 'PUBLISH_FAILED')
            ->assertJsonPath('data.last_error.message', 'Đăng lên TikTok thất bại, vui lòng thử lại.')
            ->assertJsonPath('data.last_error.platforms', ['tiktok']);
        // Mã lỗi chi tiết chỉ lưu ở job; không giao lại cho worker khác
        $this->assertSame('TIKTOK_CAPTCHA', SocialPublishJob::query()->find($jobId)->error_code);
        $this->claimPublishJob($token)->assertNoContent();

        // Người dùng bấm đăng lại: job mới
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post))
            ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.last_error', null);
        $this->assertSame(2, SocialPublishJob::query()->count());
    }

    public function test_expired_publish_lease_fails_post_without_requeue(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $token = $this->publisherToken($user);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post))->assertOk();
        $jobId = $this->claimPublishJob($token)->json('data.id');

        $this->travel(config('ai90.worker.publish_lease_minutes') + 1)->minutes();
        $this->artisan('social:sweep')->assertSuccessful();

        $this->assertSame(SocialPostStatus::PendingReview, $post->fresh()->status);
        $this->assertSame(PlatformPublishStatus::Failed, $post->fresh()->tiktok_status);
        $this->assertSame('WORKER_TIMEOUT', SocialPublishJob::query()->find($jobId)->error_code);
        $this->submitPublish($token, $jobId)->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $this->claimPublishJob($token)->assertNoContent();
    }

    public function test_queued_job_waits_until_an_active_account_worker_claims_it(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $token = $this->publisherToken($user, active: false);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post))->assertOk();

        $this->claimPublishJob($token)->assertStatus(409)->assertJsonPath('code', 'TIKTOK_ACCOUNT_NOT_ASSIGNED');
        $this->travel(2)->days();
        $this->artisan('social:sweep')->assertSuccessful();
        $this->assertSame(SocialPostStatus::Queued, $post->fresh()->status, 'Job chờ không hết hạn');

        PublishAccount::query()->update(['is_active' => true]);
        $this->claimPublishJob($token)->assertOk();
    }

    public function test_publish_validates_limits(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);

        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, [
            'title' => str_repeat('a', 91),
            'hashtags' => array_map(fn ($i) => "tag{$i}", range(1, 11)),
            'images' => [],
        ]))->assertStatus(422)->assertJsonValidationErrors(['title', 'hashtags', 'images']);
        $this->assertSame(0, SocialPublishJob::query()->count());
    }

    // ---- Nhiều nền tảng ----

    public function test_publish_to_all_assigned_platforms_each_worker_claims_its_own(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $tiktok = $this->publisherToken($user, 'tiktok-worker');
        $facebook = $this->publisherToken($user, 'facebook-worker', platform: SocialPlatform::Facebook, targetUrl: 'https://www.facebook.com/groups/123');
        $instagram = $this->publisherToken($user, 'instagram-worker', platform: SocialPlatform::Instagram);

        $this->actingAs($user)->getJson('/api/v1/social/accounts')
            ->assertOk()
            ->assertJsonPath('data.*.platform', ['tiktok', 'facebook', 'instagram'])
            ->assertJsonPath('data.1.label', 'Facebook');

        // Bắt buộc chọn nền tảng
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, ['platforms' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['platforms' => 'Chọn ít nhất một nền tảng để đăng.']);
        $body = $this->publishBody($post);
        unset($body['platforms']);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $body)->assertStatus(422)->assertJsonValidationErrors('platforms');
        $this->assertSame(0, SocialPublishJob::query()->count());

        // Chọn cả 3: 1 job / nền tảng
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, ['platforms' => ['tiktok', 'facebook', 'instagram']]))
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.platforms.tiktok.status', 'queued')
            ->assertJsonPath('data.platforms.facebook.status', 'queued')
            ->assertJsonPath('data.platforms.instagram.status', 'queued');
        $this->assertSame(['facebook', 'instagram', 'tiktok'], SocialPublishJob::query()->orderBy('platform')->pluck('platform')->map->value->all());

        // Mỗi worker chỉ nhận job của nền tảng mình
        $fbJob = $this->claimPublishJob($facebook)->assertOk()->json('data');
        $this->assertSame('facebook_publish', $fbJob['type']);
        $this->assertSame('https://www.facebook.com/groups/123', $fbJob['request']['account']['target_url']);
        $this->claimPublishJob($facebook)->assertNoContent();
        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'publishing')
            ->assertJsonPath('data.platforms.facebook.status', 'publishing')
            ->assertJsonPath('data.platforms.tiktok.status', 'queued');

        $tkJob = $this->claimPublishJob($tiktok)->assertOk()->json('data');
        $igJob = $this->claimPublishJob($instagram)->assertOk()->json('data');
        $this->assertSame('tiktok_publish', $tkJob['type']);
        $this->assertSame('instagram_publish', $igJob['type']);

        $this->submitPublish($facebook, $fbJob['id'], ['status' => 'completed', 'post_url' => 'https://www.facebook.com/groups/123/posts/9'])->assertOk();
        $this->submitPublish($tiktok, $tkJob['id'])->assertOk();
        // Còn Instagram đang đăng: bài vẫn khoá
        $this->assertSame(SocialPostStatus::Publishing, $post->fresh()->status);
        $this->submitPublish($instagram, $igJob['id'], ['status' => 'failed', 'error_code' => 'IG_LOGIN_REQUIRED'])->assertOk();

        // Xong hết, có nền tảng lỗi: về chờ gửi, báo nền tảng lỗi
        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.platforms.facebook.status', 'published')
            ->assertJsonPath('data.platforms.facebook.post_url', 'https://www.facebook.com/groups/123/posts/9')
            ->assertJsonPath('data.platforms.tiktok.status', 'published')
            ->assertJsonPath('data.platforms.instagram.status', 'failed')
            ->assertJsonPath('data.last_error.message', 'Đăng lên Instagram thất bại, vui lòng thử lại.')
            ->assertJsonPath('data.last_error.platforms', ['instagram']);
        // Đã đăng lên nền tảng khác: không xoá được
        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');

        // Không đăng lại nền tảng đã xong
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, ['platforms' => ['tiktok', 'instagram']]))
            ->assertStatus(422)->assertJsonValidationErrors('platforms');

        // Đăng lại nền tảng lỗi
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, ['platforms' => ['instagram']]))
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.platforms.instagram.status', 'queued')
            ->assertJsonPath('data.platforms.tiktok.status', 'published')
            ->assertJsonPath('data.last_error', null);
        $this->assertSame(4, SocialPublishJob::query()->count());
        $this->claimPublishJob($tiktok)->assertNoContent();

        $this->submitPublish($instagram, $this->claimPublishJob($instagram)->json('data.id'))->assertOk();
        $post->refresh();
        $this->assertSame(SocialPostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
    }

    public function test_publish_to_chosen_platforms_waits_for_unassigned_worker(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $tiktok = $this->publisherToken($user);

        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, ['platforms' => ['facebook']]))
            ->assertOk()
            ->assertJsonPath('data.platforms.facebook.status', 'queued')
            ->assertJsonMissingPath('data.platforms.tiktok');

        $this->claimPublishJob($tiktok)->assertNoContent();
        $facebook = $this->publisherToken($user, 'facebook-worker', platform: SocialPlatform::Facebook, targetUrl: 'https://www.facebook.com/a51');
        $this->claimPublishJob($facebook)->assertOk()->assertJsonPath('data.type', 'facebook_publish');
    }

    public function test_publish_validates_platform_limits(): void
    {
        config(['ai90.social.ai_content' => false]);
        RateLimiter::for('generations', fn () => Limit::none()); // tạo 11 ảnh liên tiếp
        $user = $this->buyer(credit: 50000);
        $images = array_map(fn () => $this->completedImage($user), range(1, 11));
        $post = SocialPost::query()->findOrFail($this->fromImages($user, $images, ['title' => 'x'])->json('data.id'));

        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, ['platforms' => ['instagram']]))
            ->assertStatus(422)->assertJsonValidationErrors(['images' => 'Instagram: tối đa 10 ảnh mỗi bài.']);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, [
            'platforms' => ['tiktok', 'instagram'],
            'content' => str_repeat('a', 2300),
            'images' => [['image_id' => $post->images()->value('id')]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['content' => 'Instagram: nội dung kèm hashtag tối đa 2200 ký tự.']);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, ['platforms' => ['youtube']]))
            ->assertStatus(422)->assertJsonValidationErrors('platforms.0');
        $this->assertSame(0, SocialPublishJob::query()->count());

        // TikTok / Facebook nhận 11 ảnh
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $this->publishBody($post, ['platforms' => ['tiktok', 'facebook']]))
            ->assertOk()->assertJsonPath('data.image_count', 11);
    }

    public function test_inactive_account_reports_platform_specific_code(): void
    {
        $token = $this->publisherToken($this->buyer(), 'ig-worker', active: false, platform: SocialPlatform::Instagram);

        $this->claimPublishJob($token)->assertStatus(409)->assertJsonPath('code', 'INSTAGRAM_ACCOUNT_NOT_ASSIGNED');
    }


    // ---- Xem / xoá ----

    public function test_list_and_show_only_own_posts(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $other = $this->buyer();

        $this->actingAs($user)->getJson('/api/v1/social/posts?status=pending_review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->getJson('/api/v1/social/posts?status=published')->assertJsonCount(0, 'data');

        $this->actingAs($other)->getJson('/api/v1/social/posts')->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson("/api/v1/social/posts/{$post->id}")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/social/posts/{$post->id}")->assertNotFound();
    }

    public function test_delete_only_when_pending_review(): void
    {
        $user = $this->buyer(credit: 5000);
        $generating = $this->fromImages($user, [$this->completedImage($user)])->json('data.id');

        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$generating}")
            ->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');

        $this->writeContent();
        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$generating}")->assertNoContent();
        $this->assertSame(0, SocialPost::query()->count());
        // Ảnh vẫn còn trong lịch sử tạo ảnh
        $this->assertSame(1, Generation::query()->where('user_id', $user->id)->count());
        $this->assertTrue(Transaction::query()->where('user_id', $user->id)->get()->every(fn ($tx) => $tx->status === TransactionStatus::Completed));
    }
}
