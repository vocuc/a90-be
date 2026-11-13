<?php

namespace Tests\Feature;

use App\Enums\GenerationType;
use App\Enums\OutputStatus;
use App\Enums\SocialPostStatus;
use App\Models\AiModel;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\PlanContext;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\SocialContentWriter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Kế hoạch nội dung (FlowKit a51-skills/ke-hoach-noi-dung/thiet-ke.txt mục 9): token, bối cảnh, tạo bài, làm lại. */
class PlannerApiTest extends TestCase
{
    /** @var array<int, array{request: array, images: array}> */
    public array $aiCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai90.social.ai_content' => true]);

        $this->app->instance(SocialContentWriter::class, new class($this) implements SocialContentWriter
        {
            public function __construct(private PlannerApiTest $test) {}

            public function write(array $request, array $images): ?array
            {
                $this->test->aiCalls[] = ['request' => $request, 'images' => $images];

                return ['title' => 'Giày trắng đi cafe', 'content' => 'Nhẹ nhàng cuối tuần.', 'hashtags' => []];
            }
        });
    }

    // ---- Helpers ----

    private function product(User $owner, string $name = 'Giày trắng', bool $active = true, bool $withImage = true): Product
    {
        $product = Product::query()->create(['user_id' => $owner->id, 'name' => $name, 'is_active' => $active]);
        if ($withImage) {
            $path = "products/{$product->id}/".Str::uuid().'.jpg';
            Storage::disk('media')->put($path, 'img');
            ProductImage::query()->create(['product_id' => $product->id, 'field_key' => 'product_image', 'path' => $path, 'position' => 0]);
        }

        return $product;
    }

    private function context(User $owner, array $extra = []): PlanContext
    {
        return PlanContext::query()->create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Quán cafe sáng',
            'description' => 'Bối cảnh quán cafe gỗ, nắng sớm, tông be.',
            'aspect_ratio' => '9:16',
            'model_id' => AiModel::query()->firstOrFail()->id,
        ], $extra));
    }

    private function plannerToken(User $user): string
    {
        return $this->actingAs($user)->postJson('/api/v1/planner-token')->assertCreated()->json('data.token');
    }

    private function planPost(string $token, Product $product, PlanContext $context, array $extra = []): TestResponse
    {
        // Gọi bằng token thật: bỏ user đăng nhập giả của actingAs()
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/v1/planner/posts', array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'product_id' => $product->id,
            'context_id' => $context->id,
            'scheduled_at' => now()->addDay()->setTime(9, 18)->toIso8601String(),
            'script' => [
                'y_chinh' => 'Phối giày trắng đi cafe cuối tuần',
                'items' => ['cận cảnh tay cầm giày nghiêng 45 độ', 'giày đặt trên bậc thềm gỗ'],
            ],
        ], $extra));
    }

    // ---- Token ----

    public function test_planner_token_only_reaches_planner_api_and_regenerating_revokes_old(): void
    {
        $user = $this->buyer();
        $token = $this->plannerToken($user);
        $this->actingAs($user)->getJson('/api/v1/planner-token')->assertOk()->assertJsonPath('data.exists', true);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/planner/context?from=2026-11-13&to=2026-11-15')->assertOk();
        $this->withToken($token)->getJson('/api/v1/social/posts')->assertForbidden()->assertJsonPath('code', 'TOKEN_SCOPE_DENIED');
        $this->withToken($token)->postJson('/api/v1/planner-token')->assertForbidden();

        $newToken = $this->plannerToken($user);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/planner/context?from=2026-11-13&to=2026-11-15')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($newToken)->getJson('/api/v1/planner/context?from=2026-11-13&to=2026-11-15')->assertOk();
    }

    public function test_login_token_can_call_planner_api(): void
    {
        $user = $this->buyer();
        $this->actingAs($user)->getJson('/api/v1/planner/context?from=2026-11-13&to=2026-11-13')->assertOk();
    }

    // ---- Context ----

    public function test_context_lists_usable_products_active_contexts_and_posts_in_range(): void
    {
        $user = $this->buyer();
        $product = $this->product($user);
        $this->product($user, 'Ngừng bán', active: false);
        $this->product($user, 'Chưa có ảnh', withImage: false);
        $this->product($this->buyer(), 'Shop khác');
        $context = $this->context($user);
        $this->context($user, ['name' => 'Đã tắt', 'is_active' => false]);
        $token = $this->plannerToken($user);
        $postId = $this->planPost($token, $product, $context, ['scheduled_at' => '2026-11-14T10:00:00+07:00'])->assertCreated()->json('data.id');

        $data = $this->withToken($token)->getJson('/api/v1/planner/context?from=2026-11-14&to=2026-11-14')->assertOk()->json('data');

        $this->assertSame([$product->id], array_column($data['products'], 'id'));
        $this->assertNotNull($data['products'][0]['last_planned_at']);
        $this->assertSame([$context->id], array_column($data['contexts'], 'id'));
        $this->assertSame([$postId], array_column($data['posts'], 'id'));
        $this->withToken($token)->getJson('/api/v1/planner/context?from=2026-11-15&to=2026-11-16')->assertJsonPath('data.posts', []);
    }

    // ---- Tạo bài ----

    public function test_create_plan_post_generates_free_images_from_context_and_script(): void
    {
        $user = $this->buyer();
        $product = $this->product($user);
        $product->update(['prompt_note' => 'Giữ đúng logo trên lưỡi gà.']);
        $context = $this->context($user);
        $token = $this->plannerToken($user);

        $post = $this->planPost($token, $product, $context)->assertCreated()
            ->assertJsonPath('data.source', 'plan')
            ->assertJsonPath('data.status', 'generating')
            ->assertJsonPath('data.selected_platforms', ['tiktok'])
            ->assertJsonPath('data.plan.context.id', $context->id)
            ->assertJsonPath('data.plan.script.items.1', 'giày đặt trên bậc thềm gỗ')
            ->assertJsonPath('data.image_count', 2)
            ->json('data');

        $generations = Generation::query()->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $generations);
        foreach ($generations as $g) {
            $this->assertSame(GenerationType::Plan, $g->type);
            $this->assertNull($g->creative_id);
            $this->assertSame(0, $g->price_per_image);
            $this->assertSame('9:16', $g->aspect_ratio);
        }
        $this->assertSame(
            "Bối cảnh quán cafe gỗ, nắng sớm, tông be.\n\ncận cảnh tay cầm giày nghiêng 45 độ\n\nGiữ đúng logo trên lưỡi gà.",
            $generations[0]->prompt_snapshot,
        );

        // Worker nhận job: prompt + ảnh sản phẩm, không creative
        $workerToken = $this->workerToken();
        $job = $this->claimJob($workerToken)->assertOk()->json('data');
        $this->assertStringContainsString('cận cảnh tay cầm giày', $job['request']['prompt']);
        $this->assertArrayHasKey('productImage', $job['request']);
        $this->completeJob($workerToken, $job['id'])->assertOk();
        $this->runWorker([null]);

        $fresh = SocialPost::query()->findOrFail($post['id']);
        $this->assertSame(SocialPostStatus::PendingReview, $fresh->status);
        $this->assertSame('Giày trắng đi cafe', $fresh->title);
        $this->assertSame('Phối giày trắng đi cafe cuối tuần', $this->aiCalls[0]['request']['idea']);
        $this->assertSame(0, CreditLedger::query()->where('user_id', $user->id)->count(), 'Bài kế hoạch không trừ Credit.');

        // Không hiện trong lịch sử tạo ảnh
        $this->actingAs($user)->getJson('/api/v1/generations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_same_idempotency_key_returns_same_post(): void
    {
        $user = $this->buyer();
        $product = $this->product($user);
        $context = $this->context($user);
        $token = $this->plannerToken($user);

        $first = $this->planPost($token, $product, $context, ['idempotency_key' => 'plan-2026-11-13-1'])->assertCreated()->json('data.id');
        $again = $this->planPost($token, $product, $context, ['idempotency_key' => 'plan-2026-11-13-1'])->assertOk()->json('data.id');

        $this->assertSame($first, $again);
        $this->assertSame(1, SocialPost::query()->count());
        $this->assertSame(2, Generation::query()->count());
    }

    public function test_rejects_foreign_or_inactive_context_and_unusable_product(): void
    {
        $user = $this->buyer();
        $product = $this->product($user);
        $token = $this->plannerToken($user);

        $this->planPost($token, $product, $this->context($this->buyer()))->assertStatus(422)->assertJsonPath('code', 'PLAN_CONTEXT_UNAVAILABLE');
        $this->planPost($token, $product, $this->context($user, ['is_active' => false]))->assertStatus(422)->assertJsonPath('code', 'PLAN_CONTEXT_UNAVAILABLE');
        $this->planPost($token, $this->product($user, 'Ngừng', active: false), $this->context($user))
            ->assertStatus(422)->assertJsonPath('code', 'PLAN_PRODUCT_UNAVAILABLE');
        $this->planPost($token, $product, $this->context($user), ['script' => ['y_chinh' => 'x', 'items' => array_fill(0, 7, 'ảnh')]])
            ->assertStatus(422)->assertJsonValidationErrors('script.items');
        $this->assertSame(0, SocialPost::query()->count());
    }

    // ---- Ảnh lỗi + làm lại ----

    public function test_failed_image_waits_for_redo_and_redo_regenerates_from_script(): void
    {
        $user = $this->buyer();
        $product = $this->product($user);
        $context = $this->context($user);
        $id = $this->planPost($this->plannerToken($user), $product, $context)->assertCreated()->json('data.id');

        $this->runWorker([null, 'CONTENT_BLOCKED']);
        $post = SocialPost::query()->findOrFail($id);
        $this->assertSame(SocialPostStatus::PendingReview, $post->status);
        $this->assertSame(SocialPost::PLAN_IMAGES_FAILED, $post->last_error['code']);
        $this->assertSame([], $this->aiCalls, 'Ảnh lỗi thì không viết nội dung.');

        $this->actingAs($user)->postJson("/api/v1/social/posts/{$id}/redo")->assertOk()
            ->assertJsonPath('data.status', 'generating')
            ->assertJsonPath('data.last_error', null)
            ->assertJsonPath('data.image_count', 2);
        $this->assertSame(4, Generation::query()->count());

        $this->runWorker([null, null]);
        $post->refresh();
        $this->assertSame(SocialPostStatus::PendingReview, $post->status);
        $this->assertNull($post->last_error);
        $this->assertSame('Giày trắng đi cafe', $post->title);
        $this->assertSame(0, CreditLedger::query()->where('user_id', $user->id)->count());

        // Đang tạo -> không làm lại được; bối cảnh tắt -> không làm lại được
        $context->update(['is_active' => false]);
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$id}/redo")->assertStatus(422)->assertJsonPath('code', 'PLAN_CONTEXT_UNAVAILABLE');
    }

    public function test_redo_rejects_generating_and_other_users_posts(): void
    {
        $user = $this->buyer();
        $id = $this->planPost($this->plannerToken($user), $this->product($user), $this->context($user))->assertCreated()->json('data.id');

        $this->actingAs($user)->postJson("/api/v1/social/posts/{$id}/redo")->assertStatus(409)->assertJsonPath('code', 'PLAN_POST_NOT_REDOABLE');
        $this->actingAs($this->buyer())->postJson("/api/v1/social/posts/{$id}/redo")->assertNotFound();
    }

    public function test_cancel_generating_plan_post_cancels_queued_images_and_frees_the_slot(): void
    {
        $user = $this->buyer();
        $token = $this->plannerToken($user);
        $id = $this->planPost($token, $this->product($user), $this->context($user))->assertCreated()->json('data.id');

        // Worker đã nhận 1 ảnh (đang tạo), ảnh còn lại vẫn chờ
        $worker = $this->workerToken('runner-cancel');
        $jobId = $this->claimJob($worker)->assertOk()->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->buyer())->postJson("/api/v1/social/posts/{$id}/cancel")->assertNotFound();
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$id}/cancel")->assertNoContent();

        $this->assertNull(SocialPost::query()->find($id));
        $outputs = GenerationOutput::query()->orderBy('id')->get();
        $this->assertSame([OutputStatus::Processing, OutputStatus::Failed], $outputs->pluck('status')->all());
        $this->assertSame('CANCELLED', $outputs[1]->error_code);

        // Ảnh đang tạo vẫn nộp được, không tạo lại bài
        $this->completeJob($worker, $jobId)->assertOk();
        $this->assertSame(0, SocialPost::query()->count());
        $this->assertSame([], $this->aiCalls);
        $this->assertSame(0, CreditLedger::query()->where('user_id', $user->id)->count());

        // Giờ đó trống lại cho lần lập kế hoạch sau
        $day = now()->addDay()->toDateString();
        $this->withToken($token)->getJson("/api/v1/planner/context?from={$day}&to={$day}")->assertJsonPath('data.posts', []);
    }

    public function test_cancel_rejects_posts_past_generating(): void
    {
        $user = $this->buyer();
        $id = $this->planPost($this->plannerToken($user), $this->product($user), $this->context($user))->assertCreated()->json('data.id');
        $this->runWorker([null, null]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->postJson("/api/v1/social/posts/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'POST_NOT_CANCELLABLE');
        $this->assertNotNull(SocialPost::query()->find($id));
    }

    // ---- Trang bài đăng ----

    public function test_posts_filter_by_plan_source_and_date(): void
    {
        $user = $this->buyer();
        $token = $this->plannerToken($user);
        $product = $this->product($user);
        $context = $this->context($user);
        $a = $this->planPost($token, $product, $context, ['scheduled_at' => '2026-11-14T09:00:00+07:00'])->json('data.id');
        $this->planPost($token, $product, $context, ['scheduled_at' => '2026-11-15T09:00:00+07:00']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->getJson('/api/v1/social/posts?source=plan&date_from=2026-11-14&date_to=2026-11-14')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a);
    }

    public function test_days_follow_vietnam_time_not_utc(): void
    {
        $user = $this->buyer();
        $token = $this->plannerToken($user);
        // 06:00 ngày 15 giờ Việt Nam = 23:00 ngày 14 UTC: phải tính là ngày 15
        $early = $this->planPost($token, $this->product($user), $this->context($user), ['scheduled_at' => '2026-11-15T06:00:00+07:00'])
            ->assertCreated()->json('data.id');

        $this->withToken($token)->getJson('/api/v1/planner/context?from=2026-11-14&to=2026-11-14')->assertJsonPath('data.posts', []);
        $this->withToken($token)->getJson('/api/v1/planner/context?from=2026-11-15&to=2026-11-15')->assertJsonPath('data.posts.0.id', $early);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->getJson('/api/v1/social/posts?date_from=2026-11-15&date_to=2026-11-15')->assertJsonPath('data.0.id', $early);
    }

    // ---- Trang admin ----

    public function test_admin_pages_render_plan_generations_without_creative(): void
    {
        $user = $this->buyer();
        $this->planPost($this->plannerToken($user), $this->product($user), $this->context($user))->assertCreated();
        $this->runWorker([null, 'CONTENT_BLOCKED']);
        $generation = Generation::query()->where('type', GenerationType::Plan)->firstOrFail();
        $admin = $this->buyer();
        $admin->forceFill(['role' => 'admin'])->save();

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin);
        foreach (['/admin', '/admin/generations', "/admin/generations/{$generation->id}", "/admin/users/{$user->id}"] as $url) {
            $this->get($url)->assertOk()->assertSee('Kế hoạch nội dung', false);
        }
    }

    // ---- Bối cảnh ----

    public function test_plan_context_crud_validates_model_ratio_and_ownership(): void
    {
        $user = $this->buyer();
        $id = $this->actingAs($user)->postJson('/api/v1/plan-contexts', [
            'name' => 'Studio trắng', 'description' => 'Nền trắng, đèn softbox.',
        ])->assertCreated()->assertJsonPath('data.aspect_ratio', '9:16')->assertJsonPath('data.is_active', true)->json('data.id');

        $this->actingAs($user)->putJson("/api/v1/plan-contexts/{$id}", ['aspect_ratio' => '7:5'])->assertStatus(422)->assertJsonValidationErrors('aspect_ratio');
        $this->actingAs($user)->putJson("/api/v1/plan-contexts/{$id}", ['is_active' => false, 'aspect_ratio' => '4:5'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.aspect_ratio', '4:5')->assertJsonPath('data.name', 'Studio trắng');
        $this->actingAs($this->buyer())->putJson("/api/v1/plan-contexts/{$id}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($user)->getJson('/api/v1/plan-contexts')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->deleteJson("/api/v1/plan-contexts/{$id}")->assertNoContent();
        $this->assertSame(0, PlanContext::query()->count());
    }
}
