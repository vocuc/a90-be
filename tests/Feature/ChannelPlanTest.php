<?php

namespace Tests\Feature;

use App\Enums\SocialPostStatus;
use App\Models\Channel;
use App\Models\ContentPlan;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Hashtag;
use App\Models\SocialPost;
use App\Models\TextOverlayJob;
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

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->planToken = $admin->createToken('plan:test', ['channel-plan'])->plainTextToken;

        $this->shop = $this->creator();
        [$worker, $this->workerToken] = Worker::register('dang-'.Str::random(4), Worker::TYPE_PUBLISH);
        $worker->users()->attach($this->shop->id);
        $this->channel = $this->channelFor($this->shop, $worker, [
            'persona' => ['age_range' => '18-25'],
            'plan_days' => 2,
            'posts_per_day' => 2,
            'time_slots' => [['start' => '11:00', 'end' => '13:00'], ['start' => '19:00', 'end' => '22:00']],
        ]);
        $this->channel->hashtags()->sync(Hashtag::syncPivot(['giaytet2027', 'tet2027']));
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
            ['sample_key' => 'unboxing', 'percent' => 60, 'reason' => 'bán hàng'],
            ['sample_key' => 'this_or_that', 'percent' => 40],
        ]])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->channel->lineups()->update(['status' => 'approved', 'approved_at' => now()]);
        $this->api('POST', "channels/{$this->channel->id}/text-templates", ['templates' => [$this->templateBody()]])->assertCreated();
        $this->channel->textTemplates()->update(['status' => 'approved']);
    }

    private function templateBody(): array
    {
        return ['name' => 'Ô trắng giữa', 'sample_text' => 'Đập hộp đi chúc Tết', 'style' => [
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
            'sample_key' => 'unboxing',
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
        $this->assertContains('unboxing', array_column($context['post_samples'], 'key'));
        $this->assertNotContains('top_n', array_column($context['post_samples'], 'key'), 'Chỉ gửi đủ chi tiết bài viết mẫu của tuyến');
    }

    public function test_lineup_normalized_and_auto_approve_switches(): void
    {
        $this->api('POST', "channels/{$this->channel->id}/lineups", ['items' => [
            ['sample_key' => 'unboxing', 'percent' => 1],
            ['sample_key' => 'top_n', 'percent' => 1],
            ['sample_key' => 'khong_co', 'percent' => 5],
            ['sample_key' => 'top_n', 'percent' => 9],
        ]])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items', [
                ['sample_key' => 'unboxing', 'percent' => 50, 'reason' => null],
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
        $this->assertSame(SocialPostStatus::Generating, $post->status, 'Chờ chèn chữ');
        $this->assertSame('unboxing', $post->sample_key);
        $this->assertSame(['giaytet2027'], $post->hashtags, 'Hashtag ngoài kho bị bỏ');
        $this->assertSame(SocialPost::SOURCE_PLAN, $post->source);

        // Worker nhận job chèn chữ trước (bài chưa tới giờ vẫn nhận)
        $job = $this->withToken($this->workerToken)->getJson('/api/v1/post-jobs')->assertOk()
            ->assertJsonPath('data.type', 'overlay')
            ->assertJsonPath('data.request.post_id', $post->id)
            ->assertJsonPath('data.request.images.0.text', 'Đập hộp đi chúc Tết 🧧')
            ->assertJsonPath('data.request.images.0.style.font', 'Be Vietnam Pro')
            ->json('data');
        $imageId = $job['request']['images'][0]['image_id'];
        $this->withToken($this->workerToken)->post("/api/v1/post-jobs/overlay/{$job['id']}", [
            'status' => 'completed',
            'files' => [$imageId => UploadedFile::fake()->image('overlay.jpg', 32, 32)],
        ], ['Accept' => 'application/json'])->assertOk();
        $post->refresh();
        $this->assertSame(SocialPostStatus::Queued, $post->status);
        $overlayPath = $post->images()->find($imageId)->overlay_path;
        $this->assertNotNull($overlayPath);

        // Bài thứ 2 cũng có chữ -> job chèn chữ tiếp theo; xong rồi tới giờ mới nhận bài đăng
        $job2 = $this->withToken($this->workerToken)->getJson('/api/v1/post-jobs')->assertJsonPath('data.type', 'overlay')->json('data');
        $this->withToken($this->workerToken)->post("/api/v1/post-jobs/overlay/{$job2['id']}", [
            'status' => 'failed', 'error_code' => 'FONT_MISSING', 'error_message' => 'thiếu font',
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(SocialPostStatus::PendingReview, $posts[1]->fresh()->status);
        $this->assertSame('OVERLAY_FAILED', $posts[1]->fresh()->last_error['code']);

        $this->withToken($this->workerToken)->getJson('/api/v1/post-jobs')->assertNoContent();
        $this->travelTo($post->scheduled_at->addMinute());
        $this->withToken($this->workerToken)->getJson('/api/v1/post-jobs')->assertOk()
            ->assertJsonPath('data.type', 'photo')
            ->assertJsonPath('data.request.post_id', $post->id)
            ->assertJsonPath('data.request.images.0', "https://media.test/{$overlayPath}");
    }

    public function test_edit_overlay_text_supersedes_old_job(): void
    {
        $refs = $this->images(2);
        $this->approveSetup();
        $planId = $this->context()['plan']['id'];
        $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [$this->item(0, $refs)]])->assertOk();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/plans/{$planId}/approve")->assertOk();
        $post = SocialPost::query()->where('channel_id', $this->channel->id)->firstOrFail();

        $old = $this->withToken($this->workerToken)->getJson('/api/v1/post-jobs')->json('data');
        $imageId = $old['request']['images'][0]['image_id'];
        $this->actingAs($this->shop)->putJson("/api/v1/creator/channel-posts/{$post->id}/overlays", [
            'overlays' => [['image_id' => $imageId, 'text' => 'Chữ mới']],
        ])->assertOk()->assertJsonPath('data.images.0.overlay_text', 'Chữ mới');

        $this->withToken($this->workerToken)->post("/api/v1/post-jobs/overlay/{$old['id']}", [
            'status' => 'completed', 'files' => [$imageId => UploadedFile::fake()->image('o.jpg', 8, 8)],
        ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $this->assertSame(TextOverlayJob::SUPERSEDED, TextOverlayJob::query()->find($old['id'])->status);
        $this->withToken($this->workerToken)->getJson('/api/v1/post-jobs')
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
            'sample_key' => 'this_or_that', 'hook' => 'Mùng 1 chọn A hay B?',
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

        // Có template khác đã duyệt: bỏ được, bài chuyển sang template đó và chèn chữ lại
        $other = $this->channel->textTemplates()->create(['name' => 'Khác', 'status' => 'approved', 'style' => $used->style]);
        $oldJob = TextOverlayJob::query()->where('social_post_id', $post->id)->latest('id')->firstOrFail();
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/text-templates/{$used->id}/reject")
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.moved_posts', 1);

        $this->assertSame($other->id, $post->images()->whereNotNull('overlay_text')->value('text_template_id'));
        $this->assertSame(TextOverlayJob::SUPERSEDED, $oldJob->fresh()->status);
        $this->assertSame(SocialPostStatus::Generating, $post->fresh()->status);
        $this->withToken($this->workerToken)->getJson('/api/v1/post-jobs')
            ->assertJsonPath('data.type', 'overlay')
            ->assertJsonPath('data.request.images.0.text', 'Đập hộp đi chúc Tết 🧧');
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
        $lookbook = ['slot_index' => 0, 'sample_key' => 'lookbook', 'hook' => '5 outfit đi chơi Tết',
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
        $this->assertSame(0, TextOverlayJob::query()->count(), 'A51 không chèn chữ: worker phối đồ in hook lên bìa');

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
        $job = $this->withToken($outfitToken)->getJson('/api/v1/jobs')->assertOk()->json('data');
        $this->assertTrue($job['batch']['hook_locked']);
        $this->assertSame('5 outfit đi chơi Tết', $job['batch']['title']);
        $this->assertSame(['dichoi', 'tet'], $job['batch']['outfit_tags']);
        // Template chữ của kênh để worker in hook lên ảnh bìa
        $this->assertSame($templateId, $job['batch']['text_template']['id']);
        $this->assertSame('box', $job['batch']['text_template']['style']['background']);
        $this->withToken($outfitToken)->post("/api/v1/jobs/{$job['id']}", [
            'status' => 'completed', 'title' => 'Hook khác do worker tự đặt',
            'image' => UploadedFile::fake()->image('o.png', 32, 32),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('5 outfit đi chơi Tết', $batch->fresh()->title);
        // Worker đăng bài chưa nhận được gì cho tới khi ảnh xong
        $this->withToken($this->workerToken)->getJson('/api/v1/post-jobs')->assertNoContent();
    }

    public function test_slots_spread_video_and_skip_past_times(): void
    {
        $this->channel->update(['plan_days' => 1, 'posts_per_day' => 4, 'video_percent' => 50]);
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
