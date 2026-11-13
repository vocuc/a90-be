<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Enums\SocialPostStatus;
use App\Enums\TransactionStatus;
use App\Jobs\WriteSocialPostContent;
use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\Hashtag;
use App\Models\SocialContentJob;
use App\Models\SocialPost;
use App\Models\SocialPublishJob;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VideoTemplate;
use App\Models\Worker;
use App\Services\Social\SocialContentJobService;
use App\Services\Social\SocialContentWriter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
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

    private function publishBody(SocialPost $post, Worker $worker, array $extra = []): array
    {
        return array_merge([
            'worker_id' => $worker->id,
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

        $this->travel(config('ai90.social.ai_stuck_minutes') + 1)->minutes();
        $this->artisan('social:sweep')->assertSuccessful();

        $this->getPost($user, $id)->assertJsonPath('data.status', 'pending_review');
        $this->assertSame($balance, $this->balance($user));
        $this->assertLedgerMatchesBalance($user);
        $this->assertSame([], $this->aiCalls);
    }

    public function test_cancel_while_ai_is_writing_refunds_and_deletes_post(): void
    {
        $user = $this->buyer(credit: 5000);
        $image = $this->completedImage($user);
        $balance = $this->balance($user);
        Queue::fake();

        $id = $this->fromImages($user, [$image], ['ai_content' => true])->assertJsonPath('data.status', 'generating')->json('data.id');
        $this->assertSame($balance - $this->price(), $this->balance($user));

        $this->actingAs($user)->postJson("/api/v1/social/posts/{$id}/cancel")->assertNoContent();

        $this->assertNull(SocialPost::query()->find($id));
        $this->assertSame(0, SocialContentJob::query()->count());
        $this->assertSame($balance, $this->balance($user));
        $this->assertLedgerMatchesBalance($user);
        $this->actingAs($user)->getJson("/api/v1/social/posts/{$id}")->assertNotFound();
    }

    public function test_crop_post_image_overwrites_original_output(): void
    {
        $user = $this->buyer(credit: 5000);
        $image = $this->completedImage($user);
        $id = $this->fromImages($user, [$image])->assertCreated()->json('data.id');
        $postImage = SocialPost::query()->findOrFail($id)->images()->with('output')->firstOrFail();
        $oldPath = $postImage->output->image_path;
        $balance = $this->balance($user);
        // Ảnh từ mẫu của creator: không lộ prompt của creator
        $this->actingAs($user)->getJson("/api/v1/social/posts/{$id}")->assertJsonPath('data.images.0.prompt', null);

        $data = $this->actingAs($user)->post("/api/v1/social/posts/{$id}/images/{$postImage->id}/crop", [
            'image' => UploadedFile::fake()->image('crop.jpg', 300, 400),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $postImage->id)
            ->assertJsonPath('data.output_id', $image['output_id'])
            ->json('data');

        // Ghi đè ảnh gốc: lịch sử tạo ảnh / lô / bài khác dùng output này cũng đổi theo
        $output = $postImage->output->fresh();
        $this->assertNotSame($oldPath, $output->image_path);
        $this->assertStringContainsString($output->image_path, $data['image_url']);
        Storage::disk('media')->assertExists($output->image_path);
        Storage::disk('media')->assertMissing($oldPath);
        $this->assertSame($balance, $this->balance($user));
    }

    public function test_crop_uploaded_post_image_replaces_only_post_file(): void
    {
        $user = $this->buyer();
        $post = SocialPost::query()->create([
            'user_id' => $user->id,
            'status' => SocialPostStatus::PendingReview,
            'source' => SocialPost::SOURCE_LOOKBOOK,
            'idempotency_key' => 'lookbook-crop',
            'title' => 'Lookbook',
        ]);
        Storage::disk('media')->put('social-posts/old.jpg', 'x');
        $postImage = $post->images()->create(['image_path' => 'social-posts/old.jpg', 'position' => 1]);

        $this->actingAs($user)->post("/api/v1/social/posts/{$post->id}/images/{$postImage->id}/crop", [
            'image' => UploadedFile::fake()->image('crop.jpg', 300, 400),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.id', $postImage->id);

        $postImage->refresh();
        $this->assertNotSame('social-posts/old.jpg', $postImage->image_path);
        Storage::disk('media')->assertExists($postImage->image_path);
        Storage::disk('media')->assertMissing('social-posts/old.jpg');
    }

    public function test_crop_post_image_requires_editable_own_post(): void
    {
        $user = $this->buyer(credit: 5000);
        $image = $this->completedImage($user);
        $id = $this->fromImages($user, [$image])->assertCreated()->json('data.id');
        $post = SocialPost::query()->findOrFail($id);
        $postImage = $post->images()->firstOrFail();
        $url = "/api/v1/social/posts/{$id}/images/{$postImage->id}/crop";
        $file = fn () => ['image' => UploadedFile::fake()->image('crop.jpg', 300, 400)];
        $files = count(Storage::disk('media')->allFiles());

        $this->actingAs($this->buyer())->post($url, $file(), ['Accept' => 'application/json'])->assertNotFound();

        $post->update(['status' => SocialPostStatus::Publishing]);
        $this->actingAs($user)->post($url, $file(), ['Accept' => 'application/json'])->assertStatus(409);
        // File vừa lưu bị xoá khi không ghi được
        $this->assertSame($files, count(Storage::disk('media')->allFiles()));
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

    private function aiSettings(User $owner, array $body): TestResponse
    {
        return $this->actingAs($owner)->putJson('/api/v1/creator/ai-content', array_merge([
            'hashtags' => [],
            'samples' => [],
            'title_max' => 90,
            'content_max' => 2000,
        ], $body));
    }

    public function test_shop_owner_manages_ai_content_settings(): void
    {
        $owner = $this->creator();
        $this->actingAs($owner)->post('/api/v1/creator/products', [
            'hashtags' => ['giay', 'ThoiTrang'],
            'images' => [['file' => UploadedFile::fake()->image('p.png', 32, 32), 'field_key' => 'product_image']],
        ], ['Accept' => 'application/json'])->assertCreated();

        // Chưa lưu: mặc định
        $this->actingAs($owner)->getJson('/api/v1/creator/ai-content')
            ->assertOk()
            ->assertJsonPath('data.hashtags', [])
            ->assertJsonPath('data.samples', [])
            ->assertJsonPath('data.requirements', '')
            ->assertJsonPath('data.limits.requirements', 1000)
            ->assertJsonPath('data.title_max', 90)
            ->assertJsonPath('data.content_max', 2000)
            ->assertJsonPath('data.product_hashtags', ['giay', 'ThoiTrang'])
            ->assertJsonPath('data.limits.ai_hashtags', 5);

        $this->aiSettings($owner, [
            'hashtags' => ['#xuhuong', 'giay', 'GIAY'],
            'samples' => [['title' => ' Giày mới về ', 'content' => "Siêu êm 👟\nInbox ngay!"], ['content' => 'Mẫu không tiêu đề']],
            'requirements' => "  Luôn xưng shop\nCuối bài mời theo dõi  ",
            'title_max' => 40,
            'content_max' => 300,
        ])->assertOk()
            ->assertJsonPath('data.hashtags', ['xuhuong', 'giay'])
            ->assertJsonPath('data.requirements', "Luôn xưng shop\nCuối bài mời theo dõi")
            ->assertJsonPath('data.samples.0', ['title' => 'Giày mới về', 'content' => "Siêu êm 👟\nInbox ngay!"])
            ->assertJsonPath('data.samples.1.title', '')
            ->assertJsonPath('data.title_max', 40)
            ->assertJsonPath('data.content_max', 300);

        $this->aiSettings($owner, [
            'hashtags' => ['có khoảng trắng'],
            'samples' => [['title' => 'x']],
            'title_max' => 91,
            'content_max' => 49,
        ])->assertStatus(422)->assertJsonValidationErrors(['hashtags.0', 'samples.0.content', 'title_max', 'content_max']);
        $this->aiSettings($owner, ['samples' => array_fill(0, 11, ['content' => 'x'])])
            ->assertStatus(422)->assertJsonValidationErrors('samples');
        $this->aiSettings($owner, ['requirements' => str_repeat('a', 1001)])
            ->assertStatus(422)->assertJsonValidationErrors('requirements');
        // Gửi rỗng = xoá yêu cầu
        $this->aiSettings($owner, ['requirements' => ''])->assertOk()->assertJsonPath('data.requirements', '');

        $this->actingAs($this->buyer())->getJson('/api/v1/creator/ai-content')->assertForbidden();
    }

    public function test_ai_follows_shop_samples_and_length_limits(): void
    {
        $owner = $this->creator(credit: 5000);
        $this->aiSettings($owner, [
            'samples' => [['title' => 'Giày mới về', 'content' => 'Siêu êm, inbox ngay!']],
            'requirements' => 'Luôn xưng shop',
            'title_max' => 20,
            'content_max' => 60,
        ])->assertOk();
        $this->aiResult = ['title' => str_repeat('Tiêu đề rất dài ', 5), 'content' => str_repeat('Nội dung dài. ', 20), 'hashtags' => []];

        $id = $this->fromImages($owner, [$this->completedImage($owner)], ['ai_content' => true])->json('data.id');

        $request = $this->aiCalls[0]['request'];
        $this->assertSame([['title' => 'Giày mới về', 'content' => 'Siêu êm, inbox ngay!']], $request['samples']);
        $this->assertSame('Luôn xưng shop', $request['requirements']);
        $this->assertSame(20, $request['limits']['title_max']);
        $this->assertSame(60, $request['limits']['content_max']);

        $post = $this->getPost($owner, $id)->json('data');
        $this->assertLessThanOrEqual(20, mb_strlen($post['title']));
        $this->assertLessThanOrEqual(60, mb_strlen($post['content']));
    }

    public function test_me_exposes_ai_content_setting(): void
    {
        $this->actingAs($this->buyer())->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.social_ai.enabled', true)
            ->assertJsonPath('data.social_ai.price', $this->price());
    }

    // ---- Đăng (giao cho worker, 1 job / bài chứa mọi nền tảng, note/gop-luong-dang-bai.txt) ----

    /** Worker đăng bài được admin gắn cho $user: [worker, token]. */
    private function postWorker(User $user, string $name = 'post-worker'): array
    {
        [$worker, $token] = Worker::register($name.'-'.Str::random(4), Worker::TYPE_PUBLISH);
        $worker->users()->attach($user->id);

        return [$worker, $token];
    }

    private function publish(User $user, SocialPost $post, array $body): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", $body);
    }

    private function claimPostJob(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/post-jobs');
    }

    private function submitPostJob(string $token, int $jobId, array $body = ['status' => 'completed']): TestResponse
    {
        return $this->withToken($token)->postJson("/api/v1/post-jobs/{$jobId}", $body);
    }

    public function test_publish_assigns_worker_who_posts_all_platforms_in_one_job(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        $extra = $this->completedImage($user);
        [$worker, $token] = $this->postWorker($user);
        [, $otherToken] = $this->postWorker($this->buyer(), 'other');

        $body = $this->publishBody($post, $worker, ['title' => '', 'platforms' => ['tiktok', 'facebook']]); // tiêu đề không bắt buộc
        $body['images'][] = $extra; // ảnh có sẵn thêm ở bước duyệt
        $this->publish($user, $post, $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.type', 'photo')
            ->assertJsonPath('data.worker.id', $worker->id)
            ->assertJsonPath('data.selected_platforms', ['tiktok', 'facebook'])
            ->assertJsonPath('data.platforms.tiktok.status', 'queued')
            ->assertJsonPath('data.platforms.facebook.status', 'queued')
            ->assertJsonMissingPath('data.platforms.instagram')
            ->assertJsonPath('data.hashtags', ['a51', 'ChânDung'])
            ->assertJsonPath('data.image_count', 2);
        // Không gửi giờ đăng: đăng ngay
        $this->assertTrue($post->fresh()->scheduled_at->lte(now()));

        // Chờ worker nhận: vẫn sửa được (bỏ Facebook)
        $this->publish($user, $post, ['platforms' => ['tiktok']] + $body)->assertOk()
            ->assertJsonPath('data.selected_platforms', ['tiktok'])
            ->assertJsonMissingPath('data.platforms.facebook');
        $this->publish($user, $post, $body)->assertOk();

        // Worker khác (shop khác) / worker chưa gắn shop: không thấy bài này
        $this->claimPostJob($otherToken)->assertNoContent();
        $this->claimPostJob(Worker::register('no-shop', Worker::TYPE_PUBLISH)[1])->assertNoContent();

        $job = $this->claimPostJob($token)->assertOk()
            ->assertJsonPath('data.type', 'photo')
            ->assertJsonPath('data.request.post_id', $post->id)
            ->assertJsonPath('data.request.platforms', ['tiktok', 'facebook'])
            ->assertJsonPath('data.request.description', "Nội dung đã sửa\n#a51 #ChânDung")
            ->assertJsonPath('data.request.template', null)
            ->assertJsonMissingPath('data.request.account')
            ->assertJsonCount(2, 'data.request.images')
            ->json('data');
        $this->claimPostJob($token)->assertNoContent();
        $this->assertSame(1, SocialPublishJob::query()->count());

        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'publishing')
            ->assertJsonPath('data.platforms.tiktok.status', 'publishing')
            ->assertJsonPath('data.platforms.facebook.status', 'publishing');
        // Worker đã nhận: khoá
        $this->publish($user, $post, $body)->assertStatus(409)->assertJsonPath('code', 'POST_NOT_EDITABLE');
        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');
        // Worker khác không báo kết quả hộ được
        $this->submitPostJob($otherToken, $job['id'])->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');

        $this->submitPostJob($token, $job['id'], ['status' => 'completed', 'post_url' => 'https://www.tiktok.com/@shop/photo/1'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.platforms.tiktok.status', 'published')
            ->assertJsonPath('data.platforms.facebook.status', 'published')
            ->assertJsonPath('data.platforms.tiktok.post_url', 'https://www.tiktok.com/@shop/photo/1');
        $this->assertNotNull($post->fresh()->published_at);
        $this->actingAs($user)->deleteJson("/api/v1/social/posts/{$post->id}")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_DELETABLE');
    }

    public function test_scheduled_post_waits_until_due(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        [$worker, $token] = $this->postWorker($user);

        $this->publish($user, $post, $this->publishBody($post, $worker, ['scheduled_at' => '2030-01-01T09:00:00+07:00']))
            ->assertOk()->assertJsonPath('data.scheduled_at', '2030-01-01T02:00:00.000000Z');
        $this->claimPostJob($token)->assertNoContent();

        $this->travelTo('2030-01-01 02:01:00');
        $this->claimPostJob($token)->assertOk()->assertJsonPath('data.request.post_id', $post->id);
    }

    public function test_worker_failure_returns_post_to_review_then_retry(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        [$worker, $token] = $this->postWorker($user);
        $this->publish($user, $post, $this->publishBody($post, $worker, ['platforms' => ['tiktok', 'instagram']]))->assertOk();

        $jobId = $this->claimPostJob($token)->json('data.id');
        $this->submitPostJob($token, $jobId, ['status' => 'failed', 'error_code' => 'TIKTOK_CAPTCHA', 'error_message' => 'Captcha'])->assertOk();

        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.title', 'Tiêu đề đã sửa')
            ->assertJsonPath('data.platforms.tiktok.status', 'failed')
            ->assertJsonPath('data.platforms.tiktok.error', 'Đăng thất bại, vui lòng thử lại.')
            ->assertJsonPath('data.last_error.code', 'PUBLISH_FAILED')
            ->assertJsonPath('data.last_error.platforms', ['tiktok', 'instagram']);
        // Mã lỗi chi tiết chỉ lưu ở job; không tự giao lại
        $this->assertSame('TIKTOK_CAPTCHA', SocialPublishJob::query()->find($jobId)->error_code);
        $this->claimPostJob($token)->assertNoContent();

        // Đăng lại: về chờ, worker nhận job mới
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/retry")
            ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.last_error', null)
            ->assertJsonPath('data.platforms.instagram.status', 'queued');
        $this->assertNotSame($jobId, $this->claimPostJob($token)->assertOk()->json('data.id'));
    }

    public function test_recreate_while_worker_silent_rejects_old_result(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        [$worker, $token] = $this->postWorker($user);
        $this->publish($user, $post, $this->publishBody($post, $worker))->assertOk();
        $old = $this->claimPostJob($token)->json('data.id');

        // Job không hết hạn: sweep không đụng tới
        $this->travel(2)->days();
        $this->artisan('social:sweep')->assertSuccessful();
        $this->assertSame(SocialPostStatus::Publishing, $post->fresh()->status);
        // Chưa lỗi / chưa nhận thì không "Tạo lại" được: chỉ bài đang đăng / lỗi
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$this->pendingPost($user)->id}/retry")->assertStatus(409);

        $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/retry")->assertOk()->assertJsonPath('data.status', 'queued');
        $this->submitPostJob($token, $old)->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $new = $this->claimPostJob($token)->assertOk()->json('data.id');
        $this->assertNotSame($old, $new);
        $this->submitPostJob($token, $new)->assertOk();
        $this->assertSame(SocialPostStatus::Published, $post->fresh()->status);
    }

    public function test_publish_as_video_requires_template_and_enough_images(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        [$worker, $token] = $this->postWorker($user);
        $template = VideoTemplate::query()->create([
            'user_id' => $user->id, 'name' => 'LensPop', 'video_url' => 'https://www.tiktok.com/@a/video/1', 'image_count' => 2,
        ]);

        $this->publish($user, $post, $this->publishBody($post, $worker, ['type' => 'video']))
            ->assertStatus(422)->assertJsonValidationErrors('video_template_id');
        // Bài mới có 1 ảnh, template cần 2
        $this->publish($user, $post, $this->publishBody($post, $worker, ['type' => 'video', 'video_template_id' => $template->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['images' => 'Template "LensPop" cần đúng 2 ảnh, bài đang có 1 ảnh.']);

        $body = $this->publishBody($post, $worker, ['type' => 'video', 'video_template_id' => $template->id]);
        $body['images'][] = $this->completedImage($user);
        $this->publish($user, $post, $body)->assertOk()
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.video.template.name', 'LensPop')
            ->assertJsonPath('data.image_count', 2);

        $this->claimPostJob($token)->assertOk()
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.request.template.name', 'LensPop')
            ->assertJsonPath('data.request.template.image_count', 2)
            ->assertJsonCount(2, 'data.request.images');
    }

    public function test_publish_validates_worker_platforms_and_limits(): void
    {
        $user = $this->buyer(credit: 5000);
        $post = $this->pendingPost($user);
        [$worker] = $this->postWorker($user);
        [$foreign] = $this->postWorker($this->buyer(), 'foreign');

        $this->publish($user, $post, ['worker_id' => null] + $this->publishBody($post, $worker))
            ->assertStatus(422)->assertJsonValidationErrors(['worker_id' => 'Chọn worker.']);
        $this->publish($user, $post, $this->publishBody($post, $foreign))->assertStatus(422)->assertJsonValidationErrors('worker_id');
        $this->publish($user, $post, $this->publishBody($post, $worker, ['platforms' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['platforms' => 'Chọn ít nhất một nền tảng để đăng.']);
        $this->publish($user, $post, $this->publishBody($post, $worker, ['platforms' => ['youtube']]))
            ->assertStatus(422)->assertJsonValidationErrors('platforms.0');
        $this->publish($user, $post, $this->publishBody($post, $worker, [
            'title' => str_repeat('a', 91),
            'hashtags' => array_map(fn ($i) => "tag{$i}", range(1, 11)),
            'images' => [],
        ]))->assertStatus(422)->assertJsonValidationErrors(['title', 'hashtags', 'images']);
        $this->publish($user, $post, $this->publishBody($post, $worker, ['platforms' => ['tiktok', 'instagram'], 'content' => str_repeat('a', 2300)]))
            ->assertStatus(422)->assertJsonValidationErrors(['content' => 'Instagram: nội dung kèm hashtag tối đa 2200 ký tự.']);
        $this->assertSame(SocialPostStatus::PendingReview, $post->fresh()->status);
    }

    public function test_instagram_photo_limit(): void
    {
        config(['ai90.social.ai_content' => false]);
        RateLimiter::for('generations', fn () => Limit::none()); // tạo 11 ảnh liên tiếp
        $user = $this->buyer(credit: 50000);
        [$worker] = $this->postWorker($user);
        $images = array_map(fn () => $this->completedImage($user), range(1, 11));
        $post = SocialPost::query()->findOrFail($this->fromImages($user, $images, ['title' => 'x'])->json('data.id'));

        $this->publish($user, $post, $this->publishBody($post, $worker, ['platforms' => ['instagram']]))
            ->assertStatus(422)->assertJsonValidationErrors(['images' => 'Instagram: tối đa 10 ảnh mỗi bài.']);
        // TikTok / Facebook nhận 11 ảnh
        $this->publish($user, $post, $this->publishBody($post, $worker, ['platforms' => ['tiktok', 'facebook']]))
            ->assertOk()->assertJsonPath('data.image_count', 11);
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

    public function test_list_filters_by_worker(): void
    {
        $user = $this->buyer(credit: 5000);
        [$workerA] = $this->postWorker($user, 'worker-a');
        [$workerB] = $this->postWorker($user, 'worker-b');
        $postA = $this->pendingPost($user);
        $postB = $this->pendingPost($user);
        $this->pendingPost($user); // chưa giao worker
        $postA->update(['worker_id' => $workerA->id]);
        $postB->update(['worker_id' => $workerB->id]);

        $this->actingAs($user)->getJson("/api/v1/social/posts?worker_id={$workerA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $postA->id);
        $this->actingAs($user)->getJson('/api/v1/social/posts')->assertJsonCount(3, 'data');
        $this->actingAs($user)->getJson('/api/v1/social/posts?worker_id=abc')->assertUnprocessable();
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
