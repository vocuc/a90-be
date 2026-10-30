<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Enums\TransactionStatus;
use App\Jobs\WriteSocialPostContent;
use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\Hashtag;
use App\Models\PublishAccount;
use App\Models\ShopStaff;
use App\Models\SocialContentJob;
use App\Models\SocialPost;
use App\Models\SocialPublishJob;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Worker;
use App\Services\Social\SocialContentJobService;
use App\Services\Social\SocialContentWriter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SocialPostTest extends TestCase
{
    /** Kết quả AI giả trả về (null = AI lỗi) và các lần AI được gọi. */
    public ?array $aiResult = null;

    /** @var array<int, array{request: array, images: array}> */
    public array $aiCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai90.social.ai_content' => true]);
        $this->aiResult = [
            'title' => 'Lột xác ảnh sản phẩm',
            'content' => 'Ảnh đẹp mỗi ngày.',
            'hashtags' => [],
        ];

        $this->app->instance(SocialContentWriter::class, new class($this) implements SocialContentWriter
        {
            public function __construct(private SocialPostTest $test) {}

            public function write(array $request, array $images): ?array
            {
                $this->test->aiCalls[] = ['request' => $request, 'images' => $images];

                return $this->test->aiResult;
            }
        });
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

    private function getPost(User $user, int $id): TestResponse
    {
        return $this->actingAs($user)->getJson("/api/v1/social/posts/{$id}")->assertOk();
    }

    /** @param  string[]  $hashtags */
    private function setShopHashtags(User $owner, array $hashtags): void
    {
        $owner->shopHashtags()->sync(Hashtag::syncPivot($hashtags));
    }

    private function price(): int
    {
        return config('ai90.defaults')['social.ai_content_price'];
    }

    /** Bài đang chờ duyệt từ 1 ảnh có sẵn (không bật AI). */
    private function pendingPost(User $user): SocialPost
    {
        $id = $this->fromImages($user, [$this->completedImage($user)])->assertCreated()->json('data.id');

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

    public function test_post_with_ai_content_charges_and_fills_content(): void
    {
        $user = $this->buyer(credit: 5000);
        $first = $this->completedImage($user);
        $second = $this->completedImage($user);
        $this->setShopHashtags($user, ['a51', 'AnhSanPham', 'xuhuong', 'giay', 'dep', 'sale', 'moi']);
        $this->aiResult = [
            'title' => str_repeat('Tiêu đề dài ', 20),
            'content' => "Ảnh đẹp mỗi ngày #xuhuong\nThử ngay!",
            // Ngoài kho bị bỏ, khác hoa / thường lấy theo tên trong kho, tối đa 5
            'hashtags' => ['khongcotrongkho', '#a51', 'anhsanpham', 'giay', 'dep', 'sale', 'moi'],
        ];
        $balance = $this->balance($user);

        $id = $this->fromImages($user, [$second, $first], ['ai_content' => true])
            ->assertCreated()
            ->assertJsonPath('data.source', 'existing')
            ->assertJsonPath('data.ai_content', true)
            ->assertJsonPath('data.image_count', 2)
            ->assertJsonPath('data.images.0.output_id', $second['output_id'])
            ->json('data.id');

        $this->assertSame($balance - $this->price(), $this->balance($user));
        $this->assertLedgerMatchesBalance($user);

        // AI nhận ảnh, tên mẫu và kho hashtag của shop
        $this->assertCount(1, $this->aiCalls);
        $this->assertCount(2, $this->aiCalls[0]['images']);
        $this->assertSame('Ảnh sản phẩm studio', $this->aiCalls[0]['request']['creatives'][0]['title']);
        $this->assertContains('AnhSanPham', $this->aiCalls[0]['request']['hashtags']);
        $this->assertSame(5, $this->aiCalls[0]['request']['limits']['hashtags_max']);

        $post = $this->getPost($user, $id)
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.hashtags', ['a51', 'AnhSanPham', 'giay', 'dep', 'sale'])
            ->assertJsonPath('data.content', "Ảnh đẹp mỗi ngày\nThử ngay!")
            ->json('data');
        $this->assertLessThanOrEqual(90, mb_strlen($post['title']));
        $this->assertSame(OutputStatus::Completed, SocialContentJob::query()->sole()->status);
    }

    public function test_ai_content_without_shop_hashtags_adds_no_hashtags(): void
    {
        $user = $this->buyer(credit: 5000);
        $this->aiResult = ['title' => 'Tiêu đề', 'content' => 'Nội dung', 'hashtags' => ['a51']];

        $id = $this->fromImages($user, [$this->completedImage($user)], ['ai_content' => true])->assertCreated()->json('data.id');

        $this->assertSame([], $this->aiCalls[0]['request']['hashtags']);
        $this->getPost($user, $id)->assertJsonPath('data.title', 'Tiêu đề')->assertJsonPath('data.hashtags', []);
    }

    public function test_staff_uses_hashtags_of_shop_owner(): void
    {
        $owner = $this->creator();
        $staff = $this->buyer(credit: 5000);
        ShopStaff::query()->create(['owner_id' => $owner->id, 'user_id' => $staff->id]);
        $this->setShopHashtags($owner, ['giay']);
        $this->setShopHashtags($staff, ['khongdung']);
        $this->aiResult['hashtags'] = ['giay'];
        $image = $this->completedImage($staff);
        $staffBalance = $this->balance($staff);
        $ownerBalance = $this->balance($owner);

        $id = $this->fromImages($staff, [$image], ['ai_content' => true])->json('data.id');

        $this->assertSame(['giay'], $this->aiCalls[0]['request']['hashtags']);
        $this->getPost($staff, $id)->assertJsonPath('data.hashtags', ['giay']);
        $this->assertSame($staffBalance - $this->price(), $this->balance($staff), 'Trừ ví nhân viên');
        $this->assertSame($ownerBalance, $this->balance($owner));
    }

    public function test_ai_failure_falls_back_to_creative_info_and_refunds(): void
    {
        $user = $this->buyer(credit: 5000);
        $creative = $this->publishedCreative($this->creator());
        $creative->hashtags()->attach(Hashtag::query()->create(['name' => 'MyPham', 'slug' => 'mypham'])->id, ['sort_order' => 1]);
        $image = $this->completedImage($user, $creative);
        $balance = $this->balance($user);
        $this->aiResult = null;

        $id = $this->fromImages($user, [$image], ['ai_content' => true])->assertCreated()->json('data.id');

        $this->getPost($user, $id)
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Ảnh sản phẩm studio')
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.hashtags', ['MyPham']);
        $this->assertSame($balance, $this->balance($user), 'AI lỗi thì hoàn Credit');
        $this->assertLedgerMatchesBalance($user);
        $this->assertSame(OutputStatus::Failed, SocialContentJob::query()->sole()->status);
    }

    public function test_ai_content_requires_enough_credit(): void
    {
        $user = $this->buyer(credit: 1000 + $this->price() - 1);
        $image = $this->completedImage($user);

        $this->fromImages($user, [$image], ['ai_content' => true])
            ->assertStatus(402)->assertJsonPath('code', 'INSUFFICIENT_CREDIT');
        $this->assertSame(0, SocialPost::query()->count());
        $this->assertSame([], $this->aiCalls);

        // Tắt công tắc thì tạo được, không tính phí
        $this->fromImages($user, [$image])->assertCreated()->assertJsonPath('data.status', 'pending_review');
    }

    public function test_stuck_ai_job_is_failed_by_sweep_and_refunded(): void
    {
        $user = $this->buyer(credit: 5000);
        $image = $this->completedImage($user);
        $balance = $this->balance($user);
        Queue::fake();

        $id = $this->fromImages($user, [$image], ['ai_content' => true])->assertJsonPath('data.status', 'generating')->json('data.id');
        Queue::assertPushed(WriteSocialPostContent::class);
        $this->assertSame($balance - $this->price(), $this->balance($user));

        $this->artisan('social:sweep')->assertSuccessful();
        $this->getPost($user, $id)->assertJsonPath('data.status', 'generating');

        $this->travel(config('ai90.generation.stuck_queued_minutes') + 1)->minutes();
        $this->artisan('social:sweep')->assertSuccessful();

        $this->getPost($user, $id)->assertJsonPath('data.status', 'pending_review');
        $this->assertSame($balance, $this->balance($user));
        $this->assertLedgerMatchesBalance($user);
        $this->assertSame([], $this->aiCalls);
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

    public function test_same_idempotency_key_returns_same_post_and_charges_once(): void
    {
        $user = $this->buyer(credit: 5000);
        $image = $this->completedImage($user);
        $balance = $this->balance($user);
        $body = ['images' => [$image], 'idempotency_key' => 'same-key', 'ai_content' => true];

        $first = $this->actingAs($user)->postJson('/api/v1/social/posts/from-images', $body)->assertCreated()->json('data.id');
        $second = $this->actingAs($user)->postJson('/api/v1/social/posts/from-images', $body)->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, SocialContentJob::query()->count());
        $this->assertSame($balance - $this->price(), $this->balance($user));
    }

    // ---- Không dùng AI viết nội dung ----

    public function test_without_ai_content_post_goes_to_review_with_manual_content(): void
    {
        $user = $this->buyer(credit: 5000);

        $this->fromImages($user, [$this->completedImage($user)], [
            'title' => 'Tiêu đề tự nhập',
            'content' => 'Nội dung tự nhập',
            'hashtags' => ['#a51', 'A51', 'xuhuong'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.ai_content', false)
            ->assertJsonPath('data.title', 'Tiêu đề tự nhập')
            ->assertJsonPath('data.content', 'Nội dung tự nhập')
            ->assertJsonPath('data.hashtags', ['a51', 'xuhuong']);

        $this->assertSame(0, SocialContentJob::query()->count());
    }

    public function test_without_ai_content_and_no_input_uses_creative_defaults(): void
    {
        $user = $this->buyer(credit: 5000);
        $creative = $this->publishedCreative($this->creator());
        $creative->hashtags()->attach(Hashtag::query()->create(['name' => 'MyPham', 'slug' => 'mypham'])->id, ['sort_order' => 1]);
        $image = $this->completedImage($user, $creative);
        $balance = $this->balance($user);

        $this->fromImages($user, [$image])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Ảnh sản phẩm studio')
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.hashtags', ['MyPham']);
        $this->assertSame(0, SocialContentJob::query()->count());
        $this->assertSame($balance, $this->balance($user));
    }

    public function test_ai_content_is_ignored_when_disabled_or_content_is_manual(): void
    {
        $user = $this->buyer(credit: 5000);
        $image = $this->completedImage($user);
        $balance = $this->balance($user);

        $this->fromImages($user, [$image], ['ai_content' => true, 'title' => 'Tự viết'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.ai_content', false)
            ->assertJsonPath('data.title', 'Tự viết');

        config(['ai90.social.ai_content' => false]);
        $this->fromImages($user, [$image], ['ai_content' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.ai_content', false);

        $this->assertSame(0, SocialContentJob::query()->count());
        $this->assertSame($balance, $this->balance($user));
        $this->assertSame([], $this->aiCalls);
    }

    public function test_create_validates_manual_content(): void
    {
        $user = $this->buyer(credit: 5000);

        $this->fromImages($user, [$this->completedImage($user)], [
            'title' => str_repeat('a', 91),
            'hashtags' => array_map(fn ($i) => "tag{$i}", range(1, 11)),
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'hashtags']);
        $this->assertSame(0, SocialPost::query()->count());
    }

    // ---- Viết lại bằng AI ----

    public function test_rewrite_returns_content_without_saving_and_charges(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $this->setShopHashtags($user, ['a51', 'xuhuong']);
        $this->aiResult = ['title' => 'Tiêu đề mới', 'content' => 'Nội dung mới #xuhuong', 'hashtags' => ['xuhuong', 'ngoaikho']];
        $balance = $this->balance($user);

        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/ai-content")
            ->assertOk()
            ->assertJsonPath('data.title', 'Tiêu đề mới')
            ->assertJsonPath('data.content', 'Nội dung mới')
            ->assertJsonPath('data.hashtags', ['xuhuong'])
            ->assertJsonPath('data.credit_balance', $balance - $this->price());

        $this->assertSame($post->title, $post->fresh()->title, 'Không lưu vào bài');
        $this->assertSame($balance - $this->price(), $this->balance($user));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_rewrite_does_not_charge_when_ai_fails_or_credit_is_low(): void
    {
        $user = $this->buyer(credit: 1000 + $this->price());
        $post = $this->pendingPost($user);
        $other = $this->buyer(credit: 5000);

        $this->actingAs($other)->postJson("/api/v1/social/posts/{$post->id}/ai-content")->assertNotFound();

        $this->aiResult = null;
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/ai-content")
            ->assertStatus(503)->assertJsonPath('code', 'AI_CONTENT_FAILED');
        $this->assertSame($this->price(), $this->balance($user));

        $this->aiResult = ['title' => 'x', 'content' => 'y', 'hashtags' => []];
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/ai-content")->assertOk();
        $this->assertSame(0, $this->balance($user));

        $calls = count($this->aiCalls);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/ai-content")
            ->assertStatus(402)->assertJsonPath('code', 'INSUFFICIENT_CREDIT');
        $this->assertCount($calls, $this->aiCalls, 'Không đủ Credit thì không gọi AI');

        config(['ai90.social.ai_content' => false]);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/ai-content")
            ->assertStatus(409)->assertJsonPath('code', 'AI_CONTENT_DISABLED');
    }

    // ---- Kho hashtag của shop ----

    public function test_shop_owner_manages_hashtag_pool(): void
    {
        $owner = $this->creator();
        $this->actingAs($owner)->post('/api/v1/creator/products', [
            'hashtags' => ['giay', 'ThoiTrang'],
            'images' => [['file' => UploadedFile::fake()->image('p.png', 32, 32), 'field_key' => 'product_image']],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($owner)->getJson('/api/v1/creator/shop-hashtags')
            ->assertOk()
            ->assertJsonPath('data.hashtags', [])
            ->assertJsonPath('data.product_hashtags', ['giay', 'ThoiTrang']);

        $this->actingAs($owner)->putJson('/api/v1/creator/shop-hashtags', ['hashtags' => ['#xuhuong', 'giay', 'GIAY']])
            ->assertOk()
            ->assertJsonPath('data.hashtags', ['xuhuong', 'giay']);
        $this->actingAs($owner)->putJson('/api/v1/creator/shop-hashtags', ['hashtags' => ['có khoảng trắng']])
            ->assertStatus(422)->assertJsonValidationErrors('hashtags.0');
        $this->actingAs($owner)->putJson('/api/v1/creator/shop-hashtags', ['hashtags' => []])
            ->assertOk()->assertJsonPath('data.hashtags', []);

        $this->actingAs($this->buyer())->getJson('/api/v1/creator/shop-hashtags')->assertForbidden();
    }

    public function test_me_exposes_ai_content_setting(): void
    {
        $this->actingAs($this->buyer())->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.social_ai.enabled', true)
            ->assertJsonPath('data.social_ai.price', $this->price());
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
        $image = $this->completedImage($user);
        Queue::fake();
        $generating = $this->fromImages($user, [$image], ['ai_content' => true])->json('data.id');

        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$generating}")
            ->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');

        app(SocialContentJobService::class)->run(SocialContentJob::query()->sole()->id);
        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$generating}")->assertNoContent();
        $this->assertSame(0, SocialPost::query()->count());
        // Ảnh vẫn còn trong lịch sử tạo ảnh
        $this->assertSame(1, Generation::query()->where('user_id', $user->id)->count());
        $this->assertTrue(Transaction::query()->where('user_id', $user->id)->get()->every(fn ($tx) => $tx->status === TransactionStatus::Completed));
    }
}
