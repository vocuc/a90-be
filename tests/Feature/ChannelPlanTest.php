<?php

namespace Tests\Feature;

use App\Enums\SocialPostStatus;
use App\Models\Channel;
use App\Models\ContentPlan;
use App\Models\ContentPlanItem;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Hashtag;
use App\Models\SocialPost;
use App\Models\ContentJob;
use App\Models\User;
use App\Models\Worker;
use App\Services\Channel\ChannelPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Kế hoạch nội dung của kênh + lệnh /lap-ke-hoach (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 5.4 - 5.11). */
class ChannelPlanTest extends TestCase
{
    private string $planToken;

    private User $shop;

    private Channel $channel;

    private string $workerToken;

    private string $contentToken;

    protected function setUp(): void
    {
        parent::setUp();
        // Test luồng chung với cả bài viết mẫu chưa làm xong (closeup, this_or_that...): bật hết
        config(['ai90.post_samples_enabled' => ['*']]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->planToken = $admin->createToken('plan:test', ['channel-plan'])->plainTextToken;

        $this->shop = $this->creator();
        [$worker, $this->workerToken] = Worker::register('dang-'.Str::random(4), Worker::TYPE_PUBLISH);
        $worker->users()->attach($this->shop->id);
        // Content-worker dựng bài ảnh có chữ (a90-fe note/xay-kenh-tiktok/worker-content.txt)
        [$content, $this->contentToken] = Worker::register('dung-'.Str::random(4), Worker::TYPE_CONTENT);
        $content->users()->attach($this->shop->id);
        $this->channel = $this->channelFor($this->shop, $worker, [
            'persona' => ['age_range' => '18-25'],
            'plan_days' => 2,
            'posts_per_day' => 2,
            'time_slots' => [['start' => '11:00', 'end' => '13:00'], ['start' => '19:00', 'end' => '22:00']],
        ]);
        $this->channel->hashtags()->sync(Hashtag::syncPivot(['giaytet2027', 'tet2027']));
        // Lệnh chỉ dùng chủ đề CÓ SẴN của kênh (worker lập chủ đề / chủ shop tạo)
        foreach ([...array_map(fn ($i) => "Chủ đề slot {$i}", range(0, 9)), 'Mùng 1 đi chúc Tết', 'Đi chơi Tết', '3 ngày Tết', 'Đi chúc Tết'] as $name) {
            $this->channel->topics()->create(['name' => $name, 'source' => 'ai', 'status' => 'active']);
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00'));
    }

    // ---- Helpers ----

    private function api(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->withToken($this->planToken)->json($method, "/api/v1/channel-plan/{$uri}", $body);
    }

    /** $count ảnh sản phẩm xong của shop: refs. */
    private function images(int $count, string $kind = Generation::KIND_CREATIVE): array
    {
        $response = $this->generate($this->shop, $this->publishedCreative($this->creator()), $count)->assertStatus(202);
        $this->runWorker(array_fill(0, $count, null));
        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));
        $generation->update(['kind' => $kind]);
        // Ảnh tạo xong qua API của shop (không dùng actingAs cho API lệnh)
        $this->app['auth']->forgetGuards();

        return $generation->outputs->map(fn (GenerationOutput $o) => ChannelPlanService::ref($generation->id, $o->id))->all();
    }

    private function approveSetup(): void
    {
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [
            ['sample_key' => 'closeup', 'percent' => 60, 'reason' => 'bán hàng'],
            ['sample_key' => 'this_or_that', 'percent' => 40],
        ]])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        $this->api('POST', "channels/{$this->channel->id}/text-templates", ['templates' => [$this->templateBody()]])->assertCreated();
        $this->channel->textTemplates()->update(['status' => 'approved']);
    }

    private function templateBody(): array
    {
        return ['name' => 'Ô trắng giữa', 'sample_text' => 'Đập hộp đi chúc Tết',
            'sample_keys' => ['closeup', 'this_or_that', 'lookbook'], 'style' => [
            'position' => 'top', 'font' => 'Be Vietnam Pro', 'weight' => 800, 'size' => 6, 'color' => '#111111',
            'stroke' => null, 'background' => 'box', 'box_color' => '#ffffff', 'box_radius' => 8, 'align' => 'center', 'max_lines' => 3,
        ]];
    }

    private function context(): array
    {
        return $this->api('GET', "channels/{$this->channel->id}/context")->assertOk()->json('data');
    }

    private function item(int $slot, array $refs, array $extra = []): array
    {
        return array_merge([
            'slot_index' => $slot,
            'sample_key' => 'closeup',
            // Mọi bài cần chủ đề; mỗi slot 1 chủ đề để không vướng luật lặp 14 ngày
            'topic' => ['name' => "Chủ đề slot {$slot}"],
            'hook' => 'Đập hộp đôi sandal đi chúc Tết',
            'images' => [['ref' => $refs[0], 'text' => 'Đập hộp đi chúc Tết 🧧'], ['ref' => $refs[1]]],
            'title' => 'Đập hộp sandal trắng',
            'content' => '',
            'hashtags' => ['giaytet2027', 'ngoaikho'],
        ], $extra);
    }

    // ---- Token ----

    public function test_only_admin_channel_plan_token_can_use_api_and_it_cannot_use_normal_api(): void
    {
        $userToken = $this->shop->createToken('api', ['*'])->plainTextToken;
        $this->withToken($userToken)->getJson("/api/v1/channel-plan/channels/{$this->channel->id}/context")->assertForbidden();

        $notAdmin = $this->shop->createToken('plan', ['channel-plan'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($notAdmin)->getJson("/api/v1/channel-plan/channels/{$this->channel->id}/context")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->planToken)->getJson('/api/v1/auth/me')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->api('GET', "channels/{$this->channel->id}/context")->assertOk();
    }

    // ---- Context, tuyến, template ----

    public function test_context_reports_missing_then_holds_slots(): void
    {
        $context = $this->context();
        $this->assertSame(['lineup', 'text_templates'], $context['missing']);
        $this->assertNull($context['plan'], 'Thiếu tuyến / template: chưa giữ slot');

        $this->approveSetup();
        $context = $this->context();
        $this->assertSame([], $context['missing']);
        $plan = $context['plan'];
        $this->assertSame('2026-11-20', $plan['start_date']);
        $this->assertCount(4, $plan['slots'], '2 ngày x 2 bài');
        foreach ($plan['slots'] as $slot) {
            $time = CarbonImmutable::parse($slot['at'])->format('H:i');
            $this->assertTrue(($time >= '11:00' && $time <= '13:00') || ($time >= '19:00' && $time <= '22:00'), "Slot {$time} nằm trong khung giờ");
        }
        $this->assertSame($plan['id'], $this->context()['plan']['id'], 'Gọi lại dùng lại kỳ đang giữ slot');
        $this->assertContains('closeup', array_column($context['post_samples'], 'key'));
        $this->assertNotContains('top_n', array_column($context['post_samples'], 'key'), 'Chỉ gửi đủ chi tiết bài viết mẫu của tuyến');
    }

    public function test_lineup_normalized_and_auto_approve_switches(): void
    {
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [
            ['sample_key' => 'closeup', 'percent' => 1],
            ['sample_key' => 'top_n', 'percent' => 1],
            ['sample_key' => 'khong_co', 'percent' => 5],
            ['sample_key' => 'top_n', 'percent' => 9],
        ]])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items', [
                ['sample_key' => 'closeup', 'percent' => 50, 'reason' => null],
                ['sample_key' => 'top_n', 'percent' => 50, 'reason' => null],
            ]);

        $this->channel->update(['auto_approve_lineup' => true, 'auto_approve_templates' => true]);
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [['sample_key' => 'pov', 'percent' => 100]]])
            ->assertJsonPath('data.status', 'approved');
        $this->api('POST', "channels/{$this->channel->id}/text-templates", ['templates' => [$this->templateBody()]])
            ->assertJsonPath('data.0.status', 'approved');

        $bad = $this->templateBody();
        $bad['style']['font'] = 'Comic Sans';
        $this->api('POST', "channels/{$this->channel->id}/text-templates", ['templates' => [$bad]])->assertStatus(422);

        $this->api('PUT', "channels/{$this->channel->id}/persona", ['persona' => ['tone' => 'xưng shop', 'x' => 'bỏ']])
            ->assertOk()->assertJsonPath('data.persona', ['tone' => 'xưng shop']);
    }

    // ---- Nhận bài, duyệt, chèn chữ, đăng ----

    public function test_submit_validates_items_then_approve_creates_posts_and_overlay_flow(): void
    {
        $refs = $this->images(4);
        $outfits = $this->images(1, Generation::KIND_OUTFIT);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];

        $result = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->item(0, $refs),
            // Trùng y hệt bài 0
            $this->item(1, $refs),
            // Không thuộc tuyến
            $this->item(2, $refs, ['sample_key' => 'top_n']),
            // Ảnh phối đồ ở slide cần ảnh sản phẩm + chữ ở slide giữa không được phép
            $this->item(3, $refs, ['hook' => 'Hook khác', 'images' => [['ref' => $outfits[0]], ['ref' => $refs[2], 'text' => 'x'], ['ref' => $refs[3]]]]),
            // Slot không có
            $this->item(99, $refs),
        ]])->assertOk()->json('data');

        $this->assertSame([0], $result['accepted']);
        $this->assertSame([1, 2, 3, 99], array_column($result['rejected'], 'slot_index'));
        $this->assertStringContainsString('Trùng', $result['rejected'][0]['errors'][0]);
        $this->assertSame('draft', $result['plan']['status']);

        // Gửi bổ sung slot 1 với hook khác: được nhận
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$this->item(1, $refs, ['hook' => 'Mở hộp quà Tết'])]])
            ->assertOk()->assertJsonPath('data.accepted', [1]);

        // Chủ shop duyệt
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $posts = SocialPost::query()->where('channel_id', $this->channel->id)->orderBy('scheduled_at')->get();
        $this->assertCount(2, $posts);
        $post = $posts[0];
        $this->assertSame(SocialPostStatus::Generating, $post->status, 'Chờ content-worker dựng');
        $this->assertSame('closeup', $post->sample_key);
        $this->assertSame(['giaytet2027'], $post->hashtags, 'Hashtag ngoài kho bị bỏ');
        $this->assertSame(SocialPost::SOURCE_PLAN, $post->source);

        // Worker đăng bài KHÔNG còn chèn chữ; content-worker nhận nguyên liệu (bài chưa tới giờ vẫn nhận)
        $this->withToken($this->workerToken)->getJson('/api/v1/worker-jobs')->assertNoContent();
        $job = $this->withToken($this->contentToken)->getJson('/api/v1/worker-jobs')->assertOk()
            ->assertJsonPath('data.request.post_id', $post->id)
            ->assertJsonPath('data.request.sample_key', 'closeup')
            ->assertJsonPath('data.request.cover_collage', false)
            ->assertJsonPath('data.request.images.0.text', 'Đập hộp đi chúc Tết 🧧')
            ->assertJsonPath('data.request.images.0.kind', 'product')
            ->assertJsonPath('data.request.images.0.text_template.style.font', 'Be Vietnam Pro')
            ->json('data');
        $n = count($job['request']['images']);
        // Thiếu ảnh / lệch cỡ: từ chối
        $this->withToken($this->contentToken)->post("/api/v1/worker-jobs/{$job['id']}", [
            'status' => 'completed', 'files' => [UploadedFile::fake()->image('a.jpg', 32, 32)],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'CONTENT_FILES_COUNT');
        $bad = array_map(fn ($i) => UploadedFile::fake()->image("b{$i}.jpg", 32, $i ? 40 : 32), range(0, $n - 1));
        $this->withToken($this->contentToken)->post("/api/v1/worker-jobs/{$job['id']}", ['status' => 'completed', 'files' => $bad],
            ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'CONTENT_SIZE_MISMATCH');
        $files = array_map(fn ($i) => UploadedFile::fake()->image("c{$i}.jpg", 32, 32), range(0, $n - 1));
        $this->withToken($this->contentToken)->post("/api/v1/worker-jobs/{$job['id']}", ['status' => 'completed', 'files' => $files],
            ['Accept' => 'application/json'])->assertOk();
        $post->refresh();
        $this->assertSame(SocialPostStatus::Queued, $post->status);
        $overlayPath = $post->images()->orderBy('position')->first()->overlay_path;
        $this->assertNotNull($overlayPath);
        $this->assertSame($n, $post->images()->whereNotNull('overlay_path')->count(), 'Mọi slide dùng ảnh worker dựng');

        // Bài thứ 2 cũng có chữ -> job tiếp theo, lỗi -> chờ xử lý
        $job2 = $this->withToken($this->contentToken)->getJson('/api/v1/worker-jobs')->assertOk()->json('data');
        $this->withToken($this->contentToken)->post("/api/v1/worker-jobs/{$job2['id']}", [
            'status' => 'failed', 'error_code' => 'FONT_MISSING', 'error_message' => 'thiếu font',
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(SocialPostStatus::PendingReview, $posts[1]->fresh()->status);
        $this->assertSame('CONTENT_FAILED', $posts[1]->fresh()->last_error['code']);

        $this->withToken($this->contentToken)->getJson('/api/v1/worker-jobs')->assertNoContent();
        $this->withToken($this->workerToken)->getJson('/api/v1/worker-jobs')->assertNoContent();
        $this->travelTo($post->scheduled_at->addMinute());
        $this->withToken($this->workerToken)->getJson('/api/v1/worker-jobs')->assertOk()
            ->assertJsonPath('data.type', 'publish')->assertJsonPath('data.request.type', 'photo')
            ->assertJsonPath('data.request.post_id', $post->id)
            ->assertJsonPath('data.request.images.0', "https://media.test/{$overlayPath}");
    }

    public function test_fill_missing_slots_of_approved_plan(): void
    {
        $refs = $this->images(2);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->item(0, $refs), $this->item(1, $refs, ['hook' => 'Hook 1']),
        ]])->assertOk();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        $plans = $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/plans")->assertOk()->json('data');
        $this->assertSame(2, $plans[0]['missing_slots']);
        // Chủ shop huỷ 1 bài -> slot đó cũng thiếu
        $cancelId = ContentPlanItem::query()->where('plan_id', $planId)->where('slot_index', 1)->value('id');
        $cancelPost = ContentPlanItem::query()->find($cancelId)->social_post_id;
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/items/{$cancelId}/cancel")
            ->assertOk()->assertJsonPath('data.deleted', true);
        // Nút X xoá hẳn bài khỏi kỳ + bài đăng của nó
        $this->assertNull(ContentPlanItem::query()->find($cancelId));
        $this->assertNull(SocialPost::query()->find($cancelPost));
        $this->app['auth']->forgetGuards();

        // Context chế độ bổ sung: chỉ slot thiếu + bài đang có của kỳ, không tạo kỳ preparing
        $fill = $this->api('GET', "channels/{$this->channel->id}/context?fill_plan={$planId}")->assertOk()->json('data.plan');
        $this->assertTrue($fill['fill']);
        $this->assertSame('approved', $fill['status']);
        $this->assertSame([1, 2, 3], array_column($fill['slots'], 'index'));
        $this->assertSame([0], array_column($fill['items'], 'slot_index'));
        $this->assertSame(0, ContentPlan::query()->where('status', ContentPlan::PREPARING)->count());

        $res = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->item(0, $refs, ['hook' => 'Hook 0b']),
            $this->item(1, $refs, ['hook' => 'Hook bổ sung 1']),
            $this->item(2, $refs, ['hook' => 'Hook bổ sung 2']),
        ]])->assertOk()->json('data');
        $this->assertSame([1, 2], $res['accepted']);
        $this->assertSame('approved', $res['plan']['status']);
        $this->assertStringContainsString('đã có bài', implode(' ', $res['rejected'][0]['errors']));
        // Kỳ đã duyệt: bài bổ sung thành bài đăng ngay; dòng cũ đã huỷ của slot 1 được thay
        $items = ContentPlanItem::query()->where('plan_id', $planId)->orderBy('slot_index')->get();
        $this->assertSame([0, 1, 2], $items->pluck('slot_index')->all());
        $this->assertSame(['posted', 'posted', 'posted'], $items->pluck('status')->all());
        $this->assertSame(3, SocialPost::query()->where('channel_id', $this->channel->id)->count());

        // Chủ shop sửa cấu hình 2 -> 3 bài / ngày: mỗi ngày của kỳ thiếu thêm 1 giờ đăng mới (trong khung giờ, cách bài khác)
        $this->channel->update(['posts_per_day' => 3]);
        $missing = $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/plans")->json('data.0.missing_slots');
        $this->assertSame(3, $missing); // slot 3 cũ + 2 giờ mới
        $this->app['auth']->forgetGuards();
        $fill2 = $this->api('GET', "channels/{$this->channel->id}/context?fill_plan={$planId}")->assertOk()->json('data.plan');
        $this->assertCount(3, $fill2['slots']);
        $new = collect($fill2['slots'])->where('index', '>', 3)->values();
        $this->assertCount(2, $new);
        $res2 = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->item($new[0]['index'], $refs, ['hook' => 'Hook giờ mới', 'topic' => ['name' => 'Đi chơi Tết']]),
        ]])->assertOk()->json('data');
        $this->assertSame([$new[0]['index']], $res2['accepted']);
        $this->channel->update(['posts_per_day' => 2]);

        // Slot đã qua giờ không bổ sung được
        $this->travelTo(CarbonImmutable::parse($fill['slots'][2]['at'])->addMinute());
        $late = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$this->item(3, $refs, ['hook' => 'Trễ'])]])
            ->assertOk()->json('data.rejected');
        $this->assertStringContainsString('qua giờ', implode(' ', $late[0]['errors']));
    }

    public function test_dispatcher_of_shop_takes_content_job_pinned_to_old_worker(): void
    {
        // Việc dựng gắn worker "Dựng nội dung" cũ (máy đã tắt): worker điều phối của shop vẫn nhận, việc chuyển sang nó
        $refs = $this->images(2);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$this->item(0, $refs)]])->assertOk();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        $job = ContentJob::query()->firstOrFail();
        [$dispatcher, $token] = Worker::register('dieu-phoi-'.Str::random(4), Worker::TYPE_DISPATCH);
        $dispatcher->users()->attach($this->shop->id);
        $this->assertNotSame($dispatcher->id, $job->worker_id);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/worker-jobs?types=content')->assertOk()
            ->assertJsonPath('data.id', $job->id)->assertJsonPath('data.type', 'content');
        $this->assertSame($dispatcher->id, $job->fresh()->worker_id);
        // Worker cũ không gửi kết quả được nữa
        $this->app['auth']->forgetGuards();
        $this->withToken($this->contentToken)->post("/api/v1/worker-jobs/{$job->id}", [
            'status' => 'completed', 'files' => [UploadedFile::fake()->image('o.jpg', 8, 8), UploadedFile::fake()->image('p.jpg', 8, 8)],
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_redo_button_queues_plan_job_for_dispatcher(): void
    {
        $refs = $this->images(2);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->item(0, $refs), $this->item(1, $refs, ['hook' => 'Hook 1']),
        ]])->assertOk();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        [$a, $b] = SocialPost::query()->where('channel_id', $this->channel->id)->orderBy('id')->pluck('id')->all();

        // Chưa gắn worker điều phối: chỉ đánh dấu, không tạo việc
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channel-posts/{$a}/redo")->assertOk();
        $this->assertSame(0, \App\Models\PlanJob::query()->count());
        $this->actingAs($this->shop)->deleteJson("/api/v1/creator/channel-posts/{$a}/redo")->assertOk();

        [$dispatcher, $token] = Worker::register('dieu-phoi-'.Str::random(4), Worker::TYPE_DISPATCH);
        $dispatcher->users()->attach($this->shop->id);
        // Bấm "Làm lại" 2 bài liền: 1 việc làm lại (việc đang chờ dùng chung)
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channel-posts/{$a}/redo")->assertOk();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channel-posts/{$b}/redo")->assertOk();
        $this->assertSame(1, \App\Models\PlanJob::query()->count());
        $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/plan-job")
            ->assertJsonPath('data.job.redo', true)->assertJsonPath('data.job.status', 'queued');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/worker-jobs?types=plan')->assertOk()
            ->assertJsonPath('data.type', 'plan')->assertJsonPath('data.request.redo', true);
        // Context chế độ làm lại: không giữ slot kỳ mới, có 2 bài cần làm lại
        $this->app['auth']->forgetGuards();
        $context = $this->api('GET', "channels/{$this->channel->id}/context?redo_only=1")->assertOk()->json('data');
        $this->assertNull($context['plan']);
        $this->assertCount(2, $context['redo_requests']);
        $this->assertSame(0, ContentPlan::query()->where('status', ContentPlan::PREPARING)->count());
    }

    public function test_edit_overlay_text_supersedes_old_job(): void
    {
        $refs = $this->images(2);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$this->item(0, $refs)]])->assertOk();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        $post = SocialPost::query()->where('channel_id', $this->channel->id)->firstOrFail();

        $old = $this->withToken($this->contentToken)->getJson('/api/v1/worker-jobs')->json('data');
        $imageId = $old['request']['images'][0]['image_id'];
        $this->actingAs($this->shop)->putJson("/api/v1/creator/channel-posts/{$post->id}/overlays", [
            'overlays' => [['image_id' => $imageId, 'text' => 'Chữ mới']],
        ])->assertOk()->assertJsonPath('data.images.0.overlay_text', 'Chữ mới');

        $this->withToken($this->contentToken)->post("/api/v1/worker-jobs/{$old['id']}", [
            'status' => 'completed', 'files' => [UploadedFile::fake()->image('o.jpg', 8, 8), UploadedFile::fake()->image('p.jpg', 8, 8)],
        ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $this->assertSame(ContentJob::SUPERSEDED, ContentJob::query()->find($old['id'])->status);
        $this->withToken($this->contentToken)->getJson('/api/v1/worker-jobs')
            ->assertJsonPath('data.request.images.0.text', 'Chữ mới');
    }

    public function test_redo_request_then_command_redo_and_cancel_plan(): void
    {
        $refs = $this->images(4);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->item(0, $refs, ['images' => [['ref' => $refs[0]], ['ref' => $refs[1]]]]),
        ]])->assertOk();
        $this->channel->update(['auto_approve_plan' => true]);
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->item(1, $refs, ['hook' => 'Hook 2', 'images' => [['ref' => $refs[2]], ['ref' => $refs[3]]]]),
        ]])->assertOk()->assertJsonPath('data.plan.status', 'approved');
        $post = SocialPost::query()->where('channel_id', $this->channel->id)->orderBy('scheduled_at')->firstOrFail();
        $this->assertSame(SocialPostStatus::Queued, $post->status, 'Không có chữ: vào hàng chờ đăng ngay');

        $this->actingAs($this->shop)->postJson("/api/v1/creator/channel-posts/{$post->id}/redo")->assertOk();
        $this->assertSame(SocialPostStatus::PendingReview, $post->fresh()->status, 'Chờ làm lại: worker không nhận');
        $this->app['auth']->forgetGuards();
        $context = $this->context();
        $this->assertSame($post->id, $context['redo_requests'][0]['post_id']);
        $lastPost = SocialPost::query()->where('channel_id', $this->channel->id)->max('scheduled_at');
        $this->assertSame(CarbonImmutable::parse($lastPost)->addDay()->toDateString(), $context['plan']['start_date'], 'Kỳ tiếp theo bắt đầu sau ngày cuối có bài');

        $this->api('POST', "posts/{$post->id}/redo", [
            'sample_key' => 'this_or_that', 'topic' => ['name' => 'Mùng 1 đi chúc Tết'], 'hook' => 'Mùng 1 chọn A hay B?',
            'images' => [['ref' => $refs[1], 'text' => 'A hay B?'], ['ref' => $refs[0]]],
            'title' => 'A hay B', 'hashtags' => ['tet2027'],
        ])->assertOk();
        $post->refresh();
        $this->assertNull($post->redo_requested_at);
        $this->assertSame('this_or_that', $post->sample_key);
        $this->assertSame(SocialPostStatus::Generating, $post->status, 'Có chữ: chờ chèn lại');

        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, SocialPost::query()->where('channel_id', $this->channel->id)->count());
    }

    public function test_rejecting_template_in_use_moves_posts_to_another_approved_template(): void
    {
        $refs = $this->images(2);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$this->item(0, $refs)]])->assertOk();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        $post = SocialPost::query()->where('channel_id', $this->channel->id)->firstOrFail();
        $used = $this->channel->textTemplates()->firstOrFail();

        // Không còn template đã duyệt nào khác: chặn, bài giữ nguyên
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/text-templates/{$used->id}/reject")
            ->assertStatus(409)->assertJsonPath('code', 'TEXT_TEMPLATE_IN_USE');
        $this->assertSame('approved', $used->fresh()->status);

        // Template khác đã duyệt nhưng KHÔNG gắn loại bài của bài (closeup): vẫn chặn
        $other = $this->channel->textTemplates()->create(['name' => 'Khác', 'status' => 'approved', 'style' => $used->style,
            'sample_keys' => ['this_or_that']]);
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/text-templates/{$used->id}/reject")
            ->assertStatus(409)->assertJsonPath('code', 'TEXT_TEMPLATE_IN_USE');

        // Có template khác đã duyệt gắn cùng loại bài: bỏ được, bài chuyển sang template đó và chèn chữ lại
        $other->update(['sample_keys' => ['this_or_that', 'closeup']]);
        $oldJob = ContentJob::query()->where('social_post_id', $post->id)->latest('id')->firstOrFail();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/text-templates/{$used->id}/reject")
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.moved_posts', 1);

        $this->assertSame($other->id, $post->images()->whereNotNull('overlay_text')->value('text_template_id'));
        $this->assertSame(ContentJob::SUPERSEDED, $oldJob->fresh()->status);
        $this->assertSame(SocialPostStatus::Generating, $post->fresh()->status);
        $this->withToken($this->contentToken)->getJson('/api/v1/worker-jobs')
            ->assertJsonPath('data.request.images.0.text', 'Đập hộp đi chúc Tết 🧧');
    }

    public function test_template_without_sample_keys_is_not_used(): void
    {
        $refs = $this->images(2);
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [
            ['sample_key' => 'closeup', 'percent' => 60], ['sample_key' => 'this_or_that', 'percent' => 40],
        ]])->assertCreated();
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        $template = $this->channel->textTemplates()->create(['name' => 'Chưa gắn', 'status' => 'approved',
            'style' => $this->templateBody()['style']]);

        // Template đã duyệt nhưng chưa gắn loại bài nào: coi như kênh chưa có template
        $this->assertContains('text_templates', $this->context()['missing']);

        // Gắn loại bài khác (this_or_that): bài closeup có chữ vẫn bị từ chối, gửi thẳng id cũng bị từ chối
        $template->update(['sample_keys' => ['this_or_that']]);
        $context = $this->context();
        $this->assertSame([], $context['missing']);
        $this->assertSame(['this_or_that'], $context['text_templates'][0]['sample_keys']);
        $planId = $context['plan']['id'];
        $rejected = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->item(0, $refs), $this->item(1, $refs, ['hook' => 'Hook khác', 'text_template_id' => $template->id]),
        ]])->assertOk()->json('data.rejected');
        $this->assertStringContainsString('gắn với bài viết mẫu "closeup"', implode(' ', $rejected[0]['errors']));
        $this->assertStringContainsString("text_template_id {$template->id}", implode(' ', $rejected[1]['errors']));

        // Chủ shop gắn thêm closeup ở tab Template chữ: bài được nhận với template đó
        $this->actingAs($this->shop)->putJson("/api/v1/creator/channels/{$this->channel->id}/text-templates/{$template->id}",
            ['sample_keys' => ['khong_co']])->assertStatus(422)->assertJsonValidationErrors('sample_keys.0');
        $this->actingAs($this->shop)->putJson("/api/v1/creator/channels/{$this->channel->id}/text-templates/{$template->id}",
            ['sample_keys' => ['this_or_that', 'closeup', 'closeup']])->assertOk()
            ->assertJsonPath('data.sample_keys', ['this_or_that', 'closeup']);
        $this->app['auth']->forgetGuards();
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$this->item(0, $refs)]])
            ->assertOk()->assertJsonPath('data.accepted', [0]);
        $this->assertSame($template->id, ContentPlanItem::query()->where('plan_id', $planId)->value('text_template_id'));

        // Danh sách loại bài gắn được (bài ảnh), bài trong tuyến xếp trước
        $meta = $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/text-templates")
            ->assertOk()->json('meta.post_samples');
        $this->assertTrue($meta[0]['in_lineup']);
        $this->assertContains('lookbook', array_column($meta, 'key'));
    }

    public function test_owner_adds_text_template_approved_right_away(): void
    {
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/text-templates", $this->templateBody())
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.style.box_color', '#FFFFFF');
        $bad = $this->templateBody();
        $bad['style']['size'] = 50;
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/text-templates", $bad)
            ->assertStatus(422)->assertJsonValidationErrors('style.size');
        $this->actingAs($this->creator())->postJson("/api/v1/creator/channels/{$this->channel->id}/text-templates", $this->templateBody())
            ->assertNotFound();
    }

    public function test_lookbook_creates_outfit_batch_and_post_waits_for_images(): void
    {
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [['sample_key' => 'lookbook', 'percent' => 100]]])->assertCreated();
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        $this->api('POST', "channels/{$this->channel->id}/text-templates", ['templates' => [$this->templateBody()]])->assertCreated();
        $this->channel->textTemplates()->update(['status' => 'approved']);
        $planId = $this->context()['plan']['id'];
        $lookbook = ['slot_index' => 0, 'sample_key' => 'lookbook', 'topic' => ['name' => 'Đi chơi Tết'], 'hook' => '5 outfit đi chơi Tết',
            'outfit' => ['count' => 5, 'tags' => ['Đi chơi', 'tet', 'tet']],
            'title' => 'Bạn chọn outfit số mấy?', 'hashtags' => ['tet2027']];

        // Chưa gắn worker phối đồ / số ảnh sai: từ chối
        $rejected = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $lookbook, [...$lookbook, 'slot_index' => 1, 'hook' => 'khác', 'outfit' => ['count' => 20]],
            [...$lookbook, 'slot_index' => 2, 'hook' => 'khác nữa', 'outfit' => ['count' => 5]],
        ]])->json('data.rejected');
        $this->assertStringContainsString('outfit.tags', implode(' ', $rejected[2]['errors']), 'Thiếu hashtag chủ đề');
        $this->assertStringContainsString('worker phối đồ', implode(' ', $rejected[0]['errors']));
        $this->assertStringContainsString('outfit.count', implode(' ', $rejected[1]['errors']));

        [$outfitWorker] = Worker::register('phoi-do-'.Str::random(4), Worker::TYPE_OUTFIT);
        $outfitWorker->users()->attach($this->shop->id);
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$lookbook]])
            ->assertOk()->assertJsonPath('data.accepted', [0]);
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();

        $post = SocialPost::query()->where('channel_id', $this->channel->id)->firstOrFail();
        $this->assertSame(SocialPostStatus::Generating, $post->status, 'Chờ lô phối đồ tạo ảnh');
        $this->assertNotNull($post->image_batch_id);
        $this->assertSame(5, $post->images()->count());
        $kinds = Generation::query()->whereIn('id', $post->images()->pluck('generation_id'))->orderBy('id')->pluck('kind')->all();
        $this->assertSame([Generation::KIND_OUTFIT_COVER, ...array_fill(0, 4, Generation::KIND_OUTFIT)], $kinds);
        $this->assertSame(0, ContentJob::query()->count(), 'Không giao content-worker: worker phối đồ in hook lên bìa');

        // Lô có sẵn hook (chủ đề của bài) + hashtag chủ đề, khoá không cho worker đổi
        $batch = \App\Models\ImageBatch::query()->findOrFail($post->image_batch_id);
        $this->assertSame('5 outfit đi chơi Tết', $batch->title);
        $this->assertTrue($batch->hook_locked);
        $this->assertSame(['dichoi', 'tet'], $batch->outfit_tags);
        // Không gửi text_template_id: lấy template đã duyệt đầu tiên của kênh
        $templateId = $this->channel->textTemplates()->value('id');
        $this->assertSame($templateId, $batch->text_template_id);

        // Worker phối đồ nhận job: thấy hook cố định + hashtag; gửi title khác thì A51 giữ nguyên hook
        $outfitToken = $outfitWorker->regenerateToken();
        $job = $this->withToken($outfitToken)->getJson('/api/v1/worker-jobs')->assertOk()->json('data');
        $this->assertTrue($job['batch']['hook_locked']);
        $this->assertSame('5 outfit đi chơi Tết', $job['batch']['title']);
        $this->assertSame(['dichoi', 'tet'], $job['batch']['outfit_tags']);
        // Template chữ của kênh để worker in hook lên ảnh bìa
        $this->assertSame($templateId, $job['batch']['text_template']['id']);
        $this->assertSame('box', $job['batch']['text_template']['style']['background']);
        $this->withToken($outfitToken)->post("/api/v1/worker-jobs/{$job['id']}", [
            'status' => 'completed', 'title' => 'Hook khác do worker tự đặt',
            'image' => UploadedFile::fake()->image('o.png', 32, 32),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('5 outfit đi chơi Tết', $batch->fresh()->title);
        // Worker đăng bài chưa nhận được gì cho tới khi ảnh xong
        $this->withToken($this->workerToken)->getJson('/api/v1/worker-jobs')->assertNoContent();
    }

    public function test_outfit_by_day_creates_outfit_batch_with_day_labels(): void
    {
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [['sample_key' => 'outfit_by_day', 'percent' => 100]]])->assertCreated();
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        $this->api('POST', "channels/{$this->channel->id}/text-templates", ['templates' => [[...$this->templateBody(), 'sample_keys' => ['outfit_by_day']]]])->assertCreated();
        $this->channel->textTemplates()->update(['status' => 'approved']);
        [$outfitWorker] = Worker::register('phoi-do-'.Str::random(4), Worker::TYPE_OUTFIT);
        $outfitWorker->users()->attach($this->shop->id);
        $planId = $this->context()['plan']['id'];
        $byDay = ['slot_index' => 0, 'sample_key' => 'outfit_by_day', 'topic' => ['name' => '3 ngày Tết'], 'hook' => '3 ngày Tết mặc gì cho xinh?',
            'outfit' => ['count' => 4, 'tags' => ['maudo'], 'labels' => ['Mùng 1: Áo dài đỏ 🧧', 'Mùng 2:  Váy len', 'Mùng 3: Đầm xoè']],
            'title' => 'Lưu lại kẻo Tết đứng trước tủ đồ', 'hashtags' => ['tet2027']];

        // Thiếu nhãn / trùng nhãn: từ chối
        $rejected = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            [...$byDay, 'slot_index' => 1, 'hook' => 'khác', 'outfit' => [...$byDay['outfit'], 'labels' => ['Mùng 1: x']]],
            [...$byDay, 'slot_index' => 2, 'hook' => 'khác nữa', 'outfit' => [...$byDay['outfit'], 'labels' => ['A', 'a', 'B']]],
        ]])->json('data.rejected');
        $this->assertStringContainsString('đúng 3 nhãn', implode(' ', $rejected[0]['errors']));
        $this->assertStringContainsString('trùng', implode(' ', $rejected[1]['errors']));

        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$byDay]])
            ->assertOk()->assertJsonPath('data.accepted', [0]);
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();

        $post = SocialPost::query()->where('channel_id', $this->channel->id)->firstOrFail();
        $this->assertSame(SocialPostStatus::Generating, $post->status);
        $this->assertSame(4, $post->images()->count());
        $batch = \App\Models\ImageBatch::query()->findOrFail($post->image_batch_id);
        $labels = ['Mùng 1: Áo dài đỏ', 'Mùng 2: Váy len', 'Mùng 3: Đầm xoè'];
        $this->assertSame($labels, $batch->outfit_labels, 'Bỏ emoji, gộp khoảng trắng');
        $this->assertSame('3 ngày Tết mặc gì cho xinh?', $batch->title);

        $job = $this->withToken($outfitWorker->regenerateToken())->getJson('/api/v1/worker-jobs')->assertOk()->json('data');
        $this->assertSame($labels, $job['batch']['outfit_labels']);
    }

    public function test_one_shoe_many_looks_creates_product_outfit_batch_for_flow(): void
    {
        // Bài "Outfit - theo sản phẩm" (2026-11-24): lô phối đồ gắn 1 sản phẩm có tag ootd, outfit_mode = product ->
        // worker phối đồ chọn outfit cùng loại giày + vẽ bằng Flow
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [['sample_key' => 'one_shoe_many_looks', 'percent' => 100]]])->assertCreated();
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        $this->api('POST', "channels/{$this->channel->id}/text-templates", ['templates' => [[...$this->templateBody(), 'sample_keys' => ['one_shoe_many_looks']]]])->assertCreated();
        $this->channel->textTemplates()->update(['status' => 'approved']);
        [$outfitWorker] = Worker::register('phoi-do-'.Str::random(4), Worker::TYPE_OUTFIT);
        $outfitWorker->users()->attach($this->shop->id);
        $shoe = \App\Models\Product::query()->create(['user_id' => $this->shop->id, 'name' => 'Cao gót đen', 'ai_label' => 'Cao gót mũi nhọn đen',
            'tags' => ['kieu' => 'cao_got', 'ootd' => ['giaycaogot', 'muinhon', 'giayden']]]);
        \App\Models\ProductImage::query()->create(['product_id' => $shoe->id, 'path' => 'products/shoe.png', 'field_key' => 'anh_san_pham', 'position' => 1]);
        $noOotd = \App\Models\Product::query()->create(['user_id' => $this->shop->id, 'name' => 'Chưa gắn ootd', 'tags' => ['kieu' => 'sneaker']]);
        \App\Models\ProductImage::query()->create(['product_id' => $noOotd->id, 'path' => 'products/x.png', 'field_key' => 'anh_san_pham', 'position' => 1]);
        $planId = $this->context()['plan']['id'];
        $item = ['slot_index' => 0, 'sample_key' => 'one_shoe_many_looks', 'topic' => ['name' => 'Chủ đề slot 0'],
            'hook' => '1 đôi cao gót đen phối 4 kiểu', 'outfit' => ['count' => 5, 'tags' => ['henho'], 'product_id' => $shoe->id],
            'title' => 'Bạn thích kiểu số mấy?', 'hashtags' => ['tet2027']];

        $rejected = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            [...$item, 'slot_index' => 1, 'hook' => 'không sản phẩm', 'outfit' => ['count' => 5, 'tags' => ['henho']]],
            [...$item, 'slot_index' => 2, 'hook' => 'chưa có ootd', 'outfit' => ['count' => 5, 'tags' => ['henho'], 'product_id' => $noOotd->id]],
        ]])->json('data.rejected');
        $this->assertStringContainsString('bắt buộc outfit.product_id', implode(' ', $rejected[0]['errors']));
        $this->assertStringContainsString('chưa có tag ootd', implode(' ', $rejected[1]['errors']));

        $res = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$item]])->assertOk();
        $this->assertSame([0], $res->json('data.accepted'), json_encode($res->json('data.rejected'), JSON_UNESCAPED_UNICODE));
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();

        $post = SocialPost::query()->where('channel_id', $this->channel->id)->firstOrFail();
        $this->assertSame(SocialPostStatus::Generating, $post->status);
        $this->assertSame(5, $post->images()->count());
        $batch = \App\Models\ImageBatch::query()->findOrFail($post->image_batch_id);
        $this->assertSame(\App\Models\ImageBatch::OUTFIT_MODE_PRODUCT, $batch->outfit_mode);
        $this->assertSame(['henho'], $batch->outfit_tags);
        $this->assertSame([$shoe->id], Generation::query()->whereIn('id', $post->images()->pluck('generation_id'))->distinct()->pluck('product_id')->all());

        // Bìa làm trước (như lô có sản phẩm); job gửi outfit_mode + tag ootd của sản phẩm
        $token = $outfitWorker->regenerateToken();
        $job = $this->withToken($token)->getJson('/api/v1/worker-jobs')->assertOk()->json('data');
        $this->assertSame(Generation::KIND_OUTFIT_COVER, $job['kind']);
        $this->assertSame('product', $job['batch']['outfit_mode']);
        $this->assertSame(['giaycaogot', 'muinhon', 'giayden'], $job['product']['tags']['ootd']);
        $this->assertSame('Cao gót mũi nhọn đen', $job['product']['ai_label']);
        $this->assertSame('anh_san_pham', $job['product']['images'][0]['field_key']);
    }

    public function test_only_ready_post_samples_are_usable(): void
    {
        config(['ai90.post_samples_enabled' => null]); // mặc định: PostSamples::READY
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [
            ['sample_key' => 'lookbook', 'percent' => 50], ['sample_key' => 'closeup', 'percent' => 50],
        ]])->assertStatus(422)->assertJsonPath('code', 'SAMPLE_NOT_READY');

        // Tuyến cũ (đã duyệt trước khi chặn) chỉ có bài chưa xong -> coi như thiếu tuyến
        $this->channel->lineups()->create(['items' => [['sample_key' => 'closeup', 'percent' => 100, 'reason' => null]],
            'status' => 'approved', 'approved_at' => now()]);
        $this->assertContains('lineup', $this->channel->fresh()->missing());

        $samples = collect($this->actingAs($this->shop)->getJson('/api/v1/creator/channels/post-samples')->assertOk()->json('data'))->keyBy('key');
        $this->assertTrue($samples['lookbook']['enabled']);
        $this->assertFalse($samples['closeup']['enabled']);
        $this->assertSame(['top_n', 'lookbook', 'one_shoe_many_looks', 'outfit_by_day'], \App\Models\ChannelTextTemplate::sampleKeyOptions());
    }

    public function test_unboxing_creates_auto_batch_on_approve(): void
    {
        // Kiểu bài chủ shop hay đăng: bài kế hoạch chỉ ghi sản phẩm, duyệt kỳ -> lô tự động (sản phẩm x 1 nhóm Creative)
        $creative = $this->publishedCreative($this->shop);
        $creative->hashtags()->sync(Hashtag::syncPivot(['giay']));
        $productId = $this->actingAs($this->shop)->post('/api/v1/creator/products', [
            'name' => 'Cao gót đỏ', 'hashtags' => ['giay'],
            'images' => [['file' => UploadedFile::fake()->image('p.png', 32, 32), 'field_key' => 'product_image']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $lonely = $this->actingAs($this->shop)->post('/api/v1/creator/products', [
            'name' => 'Không ghép được', 'hashtags' => ['giay'],
            'images' => [['file' => UploadedFile::fake()->image('q.png', 32, 32), 'field_key' => 'khac']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $groupId = $this->actingAs($this->shop)->postJson('/api/v1/creator/creative-groups', ['name' => 'Studio', 'creative_ids' => [$creative->id]])
            ->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();

        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [['sample_key' => 'unboxing', 'percent' => 100]]])->assertCreated();
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        // Tuyến chỉ có bài không chữ: không cần template chữ; unboxing không gắn được template chữ
        $context = $this->context();
        $this->assertNotContains('text_templates', $context['missing']);
        $this->assertFalse($context['channel']['image_worker']);
        $this->assertNotContains('unboxing', \App\Models\ChannelTextTemplate::sampleKeyOptions());
        $planId = $context['plan']['id'];
        $base = ['sample_key' => 'unboxing', 'topic' => ['name' => 'Đi chúc Tết'], 'hook' => 'Đôi cao gót đỏ đi chúc Tết',
            'title' => 'Tết này diện đôi đỏ là chuẩn', 'hashtags' => ['tet2027']];

        $rejected = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            [...$base, 'slot_index' => 0, 'product_batch' => ['product_id' => $productId]],
        ]])->assertOk()->json('data.rejected');
        $this->assertStringContainsString('worker tạo ảnh', implode(' ', $rejected[0]['errors']));
        [$imageWorker] = Worker::register('anh-'.Str::random(4), Worker::TYPE_IMAGE);
        $imageWorker->users()->attach($this->shop->id);

        // Nhóm Creative riêng của kênh khác: không dùng cho kênh này
        \App\Models\CreativeGroup::query()->whereKey($groupId)->update(['channel_id' => $this->channelFor($this->shop)->id]);
        $rejected = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            [...$base, 'slot_index' => 0, 'product_batch' => ['product_id' => $productId]],
        ]])->assertOk()->json('data.rejected');
        $this->assertStringContainsString('dùng cho kênh này', implode(' ', $rejected[0]['errors']));
        // Nhóm riêng của chính kênh này thì dùng được
        \App\Models\CreativeGroup::query()->whereKey($groupId)->update(['channel_id' => $this->channel->id]);

        $res = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            [...$base, 'slot_index' => 0, 'product_batch' => ['product_id' => $productId]],
            [...$base, 'slot_index' => 1, 'hook' => 'Hook khác', 'product_batch' => ['product_id' => $lonely]],
            [...$base, 'slot_index' => 2, 'hook' => 'Hook khác 2'],
            [...$base, 'slot_index' => 3, 'hook' => 'Hook khác 3', 'product_batch' => ['product_id' => $productId], 'images' => [['ref' => 'g1-o1']]],
        ]])->assertOk()->json('data');
        $this->assertSame([0], $res['accepted']);
        $errors = collect($res['rejected'])->keyBy('slot_index')->map(fn ($r) => implode(' ', $r['errors']));
        $this->assertStringContainsString('không ghép được với nhóm Creative', $errors[1]);
        $this->assertStringContainsString('product_batch.product_id', $errors[2]);
        $this->assertStringContainsString('không chọn ảnh có sẵn', $errors[3]);

        $item = $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}")->assertOk()->json('data.items.0');
        $this->assertSame('Cao gót đỏ', $item['product_batch']['product_name']);
        // Chủ shop sửa caption không gửi sản phẩm: giữ sản phẩm, không báo trùng với chính nó
        $this->actingAs($this->shop)->putJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/items/{$item['id']}", [
            'sample_key' => 'unboxing', 'hook' => $item['hook'], 'title' => 'Tiêu đề mới', 'hashtags' => ['tet2027'],
        ])->assertOk()->assertJsonPath('data.product_batch.product_id', $productId);

        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        $post = SocialPost::query()->where('plan_item_id', $item['id'])->firstOrFail();
        $this->assertSame(SocialPostStatus::Generating, $post->status);
        $this->assertNotNull($post->image_batch_id);
        $this->assertSame('auto', \App\Models\ImageBatch::query()->find($post->image_batch_id)->source);
        $this->assertSame(1, $post->images()->count());
        $this->assertNull($post->images()->first()->overlay_text);
        $this->assertSame('Tiêu đề mới', $post->title);

        // Lô xong -> bài sẵn sàng đăng (vào hàng chờ)
        $this->runWorker([null]);
        $this->assertSame(SocialPostStatus::Queued, $post->fresh()->status);

        // Chủ shop bấm "Tạo lại" ảnh ở trang Lô ảnh -> bài về chờ, ảnh của bài theo ảnh mới của lô
        $batchImage = \App\Models\ImageBatchImage::query()->where('image_batch_id', $post->image_batch_id)->firstOrFail();
        $regen = fn (string $key) => $this->actingAs($this->shop)
            ->postJson("/api/v1/image-batches/{$post->image_batch_id}/images/{$batchImage->id}/regenerate", ['idempotency_key' => $key]);
        $regen('r1')->assertStatus(202);
        $post->refresh();
        $this->assertSame(SocialPostStatus::Generating, $post->status);
        $this->assertSame($batchImage->fresh()->output_id, $post->images()->value('output_id'));

        // Ảnh mới lỗi -> bài chưa đăng, chờ duyệt kèm lý do
        $this->runWorker(['MODEL_ERROR']);
        $post->refresh();
        $this->assertSame(SocialPostStatus::PendingReview, $post->status);
        $this->assertSame('BATCH_IMAGES_FAILED', $post->last_error['code']);

        // Tạo lại lần nữa thành công -> bài lại vào hàng chờ đăng
        $regen('r2')->assertStatus(202);
        $this->runWorker([null]);
        $post->refresh();
        $this->assertSame(SocialPostStatus::Queued, $post->status);
        $this->assertNull($post->last_error);
        $this->assertSame('Tiêu đề mới', $post->title);
    }

    public function test_top_n_gets_collage_cover_from_content_worker(): void
    {
        // Mỗi ảnh 1 mẫu khác nhau (creative khác nhau)
        $refs = [...$this->images(1), ...$this->images(1), ...$this->images(1), ...$this->images(1)];
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [['sample_key' => 'top_n', 'percent' => 100]]])->assertCreated();
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        $this->api('POST', "channels/{$this->channel->id}/text-templates", ['templates' => [[...$this->templateBody(), 'sample_keys' => ['top_n']]]])->assertCreated();
        $this->channel->textTemplates()->update(['status' => 'approved']);
        $planId = $this->context()['plan']['id'];
        $ranked = fn (array $texts) => array_map(fn ($r, $t) => ['ref' => $r, 'text' => $t], array_slice($refs, 0, count($texts)), $texts);
        $top = ['slot_index' => 0, 'sample_key' => 'top_n', 'topic' => ['name' => 'Đi chúc Tết'], 'hook' => 'Top 4 đôi giày đi chúc Tết',
            'images' => $ranked(['#1 Cao gót, sang', '#2 Búp bê, êm', '#3 Sandal, mát', '#4 Sneaker, năng động']), 'title' => 'Bạn chốt số mấy?', 'hashtags' => ['tet2027']];

        $rejected = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            [...$top, 'slot_index' => 1, 'hook' => 'Top 5 đôi'],
            [...$top, 'slot_index' => 2, 'hook' => 'Top 4 khác', 'images' => $ranked(['#1 a', '3 b', '#2 c', '#4 d'])],
            [...$top, 'slot_index' => 3, 'hook' => 'Top 3 đôi', 'images' => $ranked(['#1 a', '#2 b', '#3 c'])],
        ]])->json('data.rejected');
        $this->assertStringContainsString('Top 5', implode(' ', $rejected[0]['errors']));
        $this->assertStringContainsString('#2', implode(' ', $rejected[1]['errors']));
        $this->assertStringContainsString('4-7 ảnh', implode(' ', $rejected[2]['errors']), 'Tối thiểu Top 4 (bìa ghép 4 ảnh)');

        $r = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$top]])->assertOk();
        $this->assertSame([0], $r->json('data.accepted'), json_encode($r->json('data.rejected'), JSON_UNESCAPED_UNICODE));
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        $post = SocialPost::query()->where('channel_id', $this->channel->id)->firstOrFail();
        $this->assertSame(4, $post->images()->count(), 'A51 không thêm bìa');

        $job = $this->withToken($this->contentToken)->getJson('/api/v1/worker-jobs')->assertOk()
            ->assertJsonPath('data.request.cover_collage', true)
            ->assertJsonPath('data.request.hook', 'Top 4 đôi giày đi chúc Tết')
            ->json('data');
        $this->assertCount(4, $job['request']['images']);
        // Thiếu bìa: từ chối; đủ 3 + 1 bìa: bìa thành slide 1
        $make = fn ($k) => array_map(fn ($i) => UploadedFile::fake()->image("t{$i}.jpg", 30, 40), range(1, $k));
        $this->withToken($this->contentToken)->post("/api/v1/worker-jobs/{$job['id']}", ['status' => 'completed', 'files' => $make(4)],
            ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'CONTENT_FILES_COUNT');
        $this->withToken($this->contentToken)->post("/api/v1/worker-jobs/{$job['id']}", ['status' => 'completed', 'files' => $make(5)],
            ['Accept' => 'application/json'])->assertOk();
        $images = $post->images()->orderBy('position')->get();
        $this->assertCount(5, $images);
        $this->assertTrue((bool) $images[0]->added_by_worker);
        $this->assertSame([1, 2, 3, 4, 5], $images->pluck('position')->all());
        $this->assertSame(SocialPostStatus::Queued, $post->fresh()->status);

        // Sửa chữ -> bỏ bìa cũ, giao job mới với 3 ảnh nguyên liệu
        $this->actingAs($this->shop)->putJson("/api/v1/creator/channel-posts/{$post->id}/overlays", [
            'overlays' => [['image_id' => $images[1]->id, 'text' => '#1 Cao gót mới']],
        ])->assertOk();
        $this->assertSame(4, $post->images()->count());
        $this->withToken($this->contentToken)->getJson('/api/v1/worker-jobs')->assertOk()
            ->assertJsonPath('data.request.images.0.text', '#1 Cao gót mới');
    }

    public function test_photo_post_with_text_needs_content_worker(): void
    {
        Worker::query()->where('type', Worker::TYPE_CONTENT)->delete();
        $refs = $this->images(2);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];
        $this->assertFalse($this->context()['channel']['content_worker']);
        $rejected = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$this->item(0, $refs)]])
            ->json('data.rejected');
        $this->assertStringContainsString('content-worker', implode(' ', $rejected[0]['errors']));
    }

    public function test_video_type_01_picks_template_and_product_images(): void
    {
        // Bài video giữ luồng "Tạo video": lệnh chọn sản phẩm, A51 chọn template + ảnh của sản phẩm, worker dựng video
        // Tuyến chỉ có bài video -> 100% giờ đăng là video (không còn ô "% bài video")
        $refs = $this->images(3);
        $product = \App\Models\Product::query()->create(['user_id' => $this->shop->id, 'name' => 'Cao gót đỏ']);
        $empty = \App\Models\Product::query()->create(['user_id' => $this->shop->id, 'name' => 'Chưa có ảnh']);
        Generation::query()->whereKey((int) explode('-', substr($refs[0], 1))[0])->update(['product_id' => $product->id]);
        \App\Models\VideoTemplate::query()->create(['user_id' => $this->shop->id, 'name' => 'Mẫu 3 ảnh',
            'video_url' => 'https://www.tiktok.com/@a/video/1', 'image_count' => 3]);
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [['sample_key' => 'video_type_01', 'percent' => 100]]])->assertCreated();
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        $context = $this->context();
        $this->assertSame([], array_values(array_diff($context['missing'], ['persona'])));
        $planId = $context['plan']['id'];
        $this->assertSame(['video'], array_values(array_unique(array_column($context['plan']['slots'], 'format'))));
        // Không gửi hook: A51 lấy tiêu đề làm hook (bài không chữ trên video)
        $base = ['sample_key' => 'video_type_01', 'title' => 'Đi tiệc là phải đỏ', 'hashtags' => ['tet2027']];

        $res = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            [...$base, 'slot_index' => 0, 'topic' => ['name' => 'Chủ đề slot 0'], 'product_id' => $product->id],
            [...$base, 'slot_index' => 1, 'topic' => ['name' => 'Chủ đề slot 1'], 'title' => 'Tiêu đề 2', 'product_id' => $empty->id],
            [...$base, 'slot_index' => 2, 'topic' => ['name' => 'Chủ đề slot 2'], 'title' => '', 'product_id' => $product->id],
        ]])->assertOk()->json('data');
        $this->assertSame([0], $res['accepted']);
        $this->assertStringContainsString('dùng tiêu đề làm hook', implode(' ', $res['rejected'][1]['errors']));
        $this->assertStringContainsString('không đủ cho video template', implode(' ', $res['rejected'][0]['errors']));

        $item = ContentPlanItem::query()->where('plan_id', $planId)->firstOrFail();
        $this->assertSame('Đi tiệc là phải đỏ', $item->hook);
        $this->assertCount(3, $item->images);
        $this->assertNotNull($item->video_template_id);

        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        $post = SocialPost::query()->where('plan_item_id', $item->id)->firstOrFail();
        $this->assertSame('video', $post->type);
        $this->assertSame('Mẫu 3 ảnh', $post->video_template_name);
        $this->assertSame($product->id, $post->product_id);
        $this->assertSame(3, $post->images()->count());
        $this->assertSame(SocialPostStatus::Queued, $post->status);
        $this->assertNotContains('video_type_01', \App\Models\ChannelTextTemplate::sampleKeyOptions());
    }

    public function test_slots_spread_video_and_skip_past_times(): void
    {
        // % video theo tuyến: Video - Type 01 chiếm 50% tuyến -> 50% giờ đăng là video
        $this->channel->update(['plan_days' => 1, 'posts_per_day' => 4]);
        $this->channel->lineups()->create(['status' => 'approved', 'approved_at' => now(), 'items' => [
            ['sample_key' => 'video_type_01', 'percent' => 50, 'reason' => null], ['sample_key' => 'closeup', 'percent' => 50, 'reason' => null],
        ]]);
        $this->assertSame(50, $this->channel->fresh()->videoPercent());
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00'));
        $slots = app(ChannelPlanService::class)->computeSlots($this->channel->fresh(), CarbonImmutable::parse('2026-11-20'));

        foreach ($slots as $slot) {
            $this->assertTrue(CarbonImmutable::parse($slot['at'])->gte(now()->addMinutes(ChannelPlanService::LEAD_MINUTES)));
        }
        $this->assertLessThanOrEqual(4, count($slots));
        $this->assertGreaterThanOrEqual(2, count($slots), 'Khung tối còn nguyên 2 bài');

        $evening = collect($slots)->map(fn ($s) => CarbonImmutable::parse($s['at']))->filter(fn ($t) => $t->hour >= 19)->values();
        $this->assertCount(2, $evening);
        $this->assertGreaterThanOrEqual(ChannelPlanService::MIN_GAP_MINUTES, $evening[0]->diffInMinutes($evening[1]));
        $this->assertSame((int) round(count($slots) / 2), collect($slots)->where('format', 'video')->count());
    }
}
