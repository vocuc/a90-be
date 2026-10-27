<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\PublishDriver;
use App\Enums\SocialPlatform;
use App\Enums\TikTokConnectionStatus;
use App\Models\Generation;
use App\Models\PublishAccount;
use App\Models\SocialPost;
use App\Models\SocialPublishJob;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTikTok;
use Tests\TestCase;

/** Đăng TikTok qua API, người dùng tự kết nối (note/dang-tiktok-qua-api.txt ở repo frontend). */
class TikTokApiTest extends TestCase
{
    use InteractsWithTikTok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTikTok();
    }

    // ---- Helpers ----

    /** Bài chờ gửi từ 1 ảnh có sẵn (không bật AI viết nội dung: vào pending_review ngay). */
    private function pendingPost(User $user): SocialPost
    {
        $response = $this->generate($user, $this->publishedCreative($this->creator()))->assertStatus(202);
        $this->runWorker([null]);
        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));

        $id = $this->actingAs($user)->postJson('/api/v1/social/posts/from-images', [
            'images' => [['generation_id' => $generation->id, 'output_id' => $generation->outputs[0]->id]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        return SocialPost::query()->findOrFail($id);
    }

    private function publish(User $user, SocialPost $post, array $platforms = ['tiktok'], ?array $tiktokAccountIds = null)
    {
        return $this->actingAs($user)->postJson("/api/v1/social/posts/{$post->id}/publish", [
            'title' => 'Tiêu đề',
            'content' => 'Nội dung',
            'hashtags' => ['a51'],
            'images' => $post->images()->get()->map(fn ($image) => ['image_id' => $image->id])->all(),
            'platforms' => $platforms,
            'tiktok_account_ids' => $tiktokAccountIds,
        ]);
    }

    private function workerAccount(User $user, string $name = 'tiktok-worker'): string
    {
        $token = $this->workerToken($name);
        PublishAccount::query()->create([
            'user_id' => $user->id,
            'platform' => SocialPlatform::Tiktok,
            'name' => 'A51 Official',
            'username' => 'a51.vn',
            'worker_id' => Worker::query()->where('name', $name)->value('id'),
            'is_active' => true,
        ]);

        return $token;
    }

    private function authorizeState(User $user, string $redirect = '/posts/9'): string
    {
        $url = $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/authorize', ['redirect' => $redirect])
            ->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    // ---- Kết nối ----

    public function test_authorize_then_callback_connects_account_as_api_driver(): void
    {
        $user = $this->buyer();
        $url = $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/authorize', ['redirect' => '/posts/9'])
            ->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('https://app.test/mxh/tiktok/author', $query['redirect_uri']);
        $this->assertStringContainsString('video.upload', $query['scope']);

        $this->fakeTikTok([
            '/oauth/token/' => self::tokenResponse(),
            '/user/info/*' => self::userInfoResponse(),
        ]);
        $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/callback', ['code' => 'code-1', 'state' => $query['state']])
            ->assertOk()
            ->assertJsonPath('data', ['status' => 'connected', 'reason' => null, 'redirect' => '/posts/9']);

        $account = $user->tiktokConnections()->firstOrFail();
        $this->assertSame(PublishDriver::Api, $account->driver);
        $this->assertSame('access-new', $account->access_token);
        $this->assertSame('shophoa', $account->username);

        // state dùng 1 lần
        $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/callback', ['code' => 'code-1', 'state' => $query['state']])
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.reason', 'state_invalid')
            ->assertJsonPath('data.redirect', '/posts');
    }

    public function test_callback_errors(): void
    {
        $user = $this->buyer();
        $other = $this->buyer();

        // state của người khác
        $state = $this->authorizeState($user);
        $this->actingAs($other)->postJson('/api/v1/social/tiktok/oauth/callback', ['code' => 'c', 'state' => $state])
            ->assertJsonPath('data.reason', 'state_invalid');

        $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/callback', ['error' => 'access_denied', 'state' => $this->authorizeState($user)])
            ->assertJsonPath('data.reason', 'access_denied')
            ->assertJsonPath('data.redirect', '/posts/9');

        $this->fakeTikTok(['/oauth/token/' => self::tokenResponse(['scope' => 'user.info.basic'])]);
        $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/callback', ['code' => 'c', 'state' => $this->authorizeState($user)])
            ->assertJsonPath('data.reason', 'scope_missing');

        // redirect không an toàn -> mặc định
        $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/callback', ['error' => 'x', 'state' => $this->authorizeState($user, '//evil.test')])
            ->assertJsonPath('data.reason', 'server_error')
            ->assertJsonPath('data.redirect', '/posts');

        $this->assertNull($user->tiktokConnections()->first());
    }

    public function test_accounts_prefer_api_row_over_worker_row(): void
    {
        $user = $this->buyer();
        $this->workerAccount($user);

        $this->actingAs($user)->getJson('/api/v1/social/accounts')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.driver', 'worker')
            ->assertJsonPath('data.0.connection', null);

        $this->connectTikTok($user, ['connection_status' => TikTokConnectionStatus::Expired]);
        $this->actingAs($user)->getJson('/api/v1/social/accounts')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.driver', 'api')
            ->assertJsonPath('data.0.username', 'shophoa')
            ->assertJsonPath('data.0.connection.status', 'expired');
    }

    public function test_accounts_refresh_expired_avatar_from_user_info_and_cache_it(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user, ['avatar_url' => 'https://p16.tiktokcdn.test/old.jpg?x-expires=1']);
        $fresh = 'https://p16.tiktokcdn.test/new.jpg?x-expires='.now()->addDays(2)->timestamp;
        $this->fakeTikTok(['/user/info/*' => self::userInfoResponse(['avatar_url' => $fresh])]);

        $this->actingAs($user)->getJson('/api/v1/social/accounts')->assertJsonPath('data.0.connection.avatar_url', $fresh);
        $this->actingAs($user)->getJson('/api/v1/social/accounts')->assertJsonPath('data.0.connection.avatar_url', $fresh);
        Http::assertSentCount(1);
    }

    public function test_accounts_fall_back_to_saved_avatar_when_user_info_fails(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user, ['avatar_url' => 'https://p16.tiktokcdn.test/old.jpg']);
        $this->fakeTikTok(['/user/info/*' => Http::response([], 500)]);

        $this->actingAs($user)->getJson('/api/v1/social/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.connection.status', 'active')
            ->assertJsonPath('data.0.connection.avatar_url', 'https://p16.tiktokcdn.test/old.jpg');
    }

    public function test_disconnect_revokes_and_falls_back_to_worker(): void
    {
        $user = $this->buyer();
        $this->workerAccount($user);
        $account = $this->connectTikTok($user);
        // Tài khoản của người khác -> 404
        $this->actingAs($this->buyer())->deleteJson('/api/v1/social/tiktok/accounts/'.$account->id)->assertNotFound();
        $this->fakeTikTok(['/oauth/revoke/' => Http::response()]);

        $this->actingAs($user)->deleteJson('/api/v1/social/tiktok/accounts/'.$account->id)->assertNoContent();

        $this->assertNull($user->tiktokConnections()->first());
        $this->actingAs($user)->getJson('/api/v1/social/accounts')->assertJsonPath('data.0.driver', 'worker');
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/oauth/revoke/') && $request['token'] === 'access-'.$user->id);
    }

    // ---- Gửi bài ----

    public function test_connected_user_publishes_via_api_and_webhook_marks_inbox_delivered(): void
    {
        $user = $this->buyer(credit: 5000);
        $workerToken = $this->workerAccount($user);
        $account = $this->connectTikTok($user);
        $post = $this->pendingPost($user);
        $this->fakeTikTok(['/post/publish/content/init/' => self::tiktokOk(['publish_id' => 'p_pub_1'])]);

        // Queue sync: job gửi xong ngay trong request
        $this->publish($user, $post)
            ->assertOk()
            ->assertJsonPath('data.status', 'publishing')
            ->assertJsonPath('data.platforms.tiktok.status', 'publishing')
            ->assertJsonPath('data.platforms.tiktok.driver', 'api');

        $job = SocialPublishJob::query()->sole();
        $this->assertSame(PublishDriver::Api, $job->driver);
        $this->assertSame($account->id, $job->publish_account_id);
        $this->assertSame('p_pub_1', $job->external_id);
        Http::assertSent(function (HttpRequest $request) {
            return str_ends_with($request->url(), '/post/publish/content/init/')
                && $request['post_mode'] === 'MEDIA_UPLOAD'
                && $request['media_type'] === 'PHOTO'
                && $request['post_info'] === ['title' => 'Tiêu đề', 'description' => "Nội dung\n#a51"]
                && str_starts_with($request['source_info']['photo_images'][0], 'https://media.a51.test/tiktok/');
        });
        $this->assertCount(1, Storage::disk('tiktok_media')->allFiles());

        // Worker không nhận job api
        $this->withToken($workerToken)->getJson('/api/v1/publish-jobs')->assertNoContent();

        $this->sendWebhook('post.publish.inbox_delivered', ['publish_id' => 'p_pub_1'], 'open-'.$user->id)->assertOk();

        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.platforms.tiktok.status', 'published');
        $this->assertSame(OutputStatus::Completed, $job->fresh()->status);
        // Ảnh tạm gửi TikTok đã xoá
        $this->assertCount(0, Storage::disk('tiktok_media')->allFiles());
    }

    public function test_user_without_connection_still_publishes_via_worker(): void
    {
        $user = $this->buyer(credit: 5000);
        $workerToken = $this->workerAccount($user);
        $post = $this->pendingPost($user);
        Http::fake();

        $this->publish($user, $post)->assertOk()->assertJsonPath('data.status', 'queued');

        $this->assertSame(PublishDriver::Worker, SocialPublishJob::query()->sole()->driver);
        $this->withToken($workerToken)->getJson('/api/v1/publish-jobs')->assertOk();
        Http::assertNothingSent();
    }

    public function test_lost_connection_rejects_whole_publish_without_falling_back(): void
    {
        $user = $this->buyer(credit: 5000);
        $this->workerAccount($user);
        $this->connectTikTok($user, ['connection_status' => TikTokConnectionStatus::Revoked, 'refresh_token' => null]);
        $post = $this->pendingPost($user);

        $this->publish($user, $post, ['tiktok', 'facebook'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'TIKTOK_NOT_CONNECTED')
            ->assertJsonPath('context.reason', 'revoked');

        $this->assertSame(0, SocialPublishJob::query()->count());
        $this->assertSame('pending_review', $post->fresh()->status->value);
    }

    public function test_tiktok_rejection_fails_platform_with_specific_message(): void
    {
        $user = $this->buyer(credit: 5000);
        $this->connectTikTok($user);
        $post = $this->pendingPost($user);
        $this->fakeTikTok(['/post/publish/content/init/' => Http::response(self::tiktokError('spam_risk_too_many_posts'), 403)]);

        $this->publish($user, $post)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.platforms.tiktok.status', 'failed')
            ->assertJsonPath('data.platforms.tiktok.error', 'Tài khoản TikTok đã đạt giới hạn gửi bài trong ngày, thử lại sau.');

        $job = SocialPublishJob::query()->sole();
        $this->assertSame('TIKTOK_SPAM_RISK_TOO_MANY_POSTS', $job->error_code);
        $this->assertCount(0, Storage::disk('tiktok_media')->allFiles());
    }

    public function test_expired_access_token_is_refreshed_before_sending(): void
    {
        $user = $this->buyer(credit: 5000);
        $account = $this->connectTikTok($user, ['access_token_expires_at' => now()->subMinute()]);
        $post = $this->pendingPost($user);
        $this->fakeTikTok([
            '/oauth/token/' => self::tokenResponse(['open_id' => $account->open_id]),
            '/post/publish/content/init/' => self::tiktokOk(['publish_id' => 'p_pub_2']),
        ]);

        $this->publish($user, $post)->assertOk()->assertJsonPath('data.platforms.tiktok.status', 'publishing');

        $this->assertSame('access-new', $account->fresh()->access_token);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/content/init/') && $request->hasHeader('Authorization', 'Bearer access-new'));
    }

    public function test_admin_looks_up_publish_status_by_publish_id(): void
    {
        $user = $this->buyer(credit: 5000);
        $this->connectTikTok($user);
        $post = $this->pendingPost($user);
        $this->fakeTikTok(['/post/publish/content/init/' => self::tiktokOk(['publish_id' => 'p_inbox_url~v2.1'])]);
        $this->publish($user, $post)->assertOk();

        $this->get('/admin/tiktok-status')->assertRedirect('/admin/login');
        $admin = $this->buyer();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->get('/admin/tiktok-status')->assertOk()->assertSee('publish_id');

        $this->fakeTikTok(['/post/publish/status/fetch/' => self::tiktokOk(['status' => 'SEND_TO_USER_INBOX'])]);
        $this->get('/admin/tiktok-status?publish_id='.urlencode('p_inbox_url~v2.1'))
            ->assertOk()->assertSee('SEND_TO_USER_INBOX')->assertSee('@shophoa');
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/status/fetch/') && $request['publish_id'] === 'p_inbox_url~v2.1');
        // Chỉ xem: job vẫn đang gửi, chờ webhook / sweep
        $this->assertSame(PlatformPublishStatus::Publishing, $post->fresh()->tiktok_status);

        $this->get('/admin/tiktok-status?publish_id=khong-co')->assertOk()->assertSee('Không tìm thấy job');
    }

    public function test_sweep_fetches_status_when_webhook_is_missing_then_times_out(): void
    {
        $user = $this->buyer(credit: 5000);
        $this->connectTikTok($user);
        $first = $this->pendingPost($user);
        $second = $this->pendingPost($user);
        $this->fakeTikTok(['/post/publish/content/init/' => Http::sequence()
            ->push(self::tiktokOk(['publish_id' => 'p_a']))
            ->push(self::tiktokOk(['publish_id' => 'p_b']))]);
        $this->publish($user, $first)->assertOk();
        $this->publish($user, $second)->assertOk();

        $this->travel(6)->minutes();
        $this->fakeTikTok(['/post/publish/status/fetch/' => function (HttpRequest $request) {
            return Http::response($request['publish_id'] === 'p_a'
                ? self::tiktokOk(['status' => 'SEND_TO_USER_INBOX'])
                : self::tiktokOk(['status' => 'PROCESSING_DOWNLOAD']));
        }]);
        $this->artisan('social:sweep')->assertSuccessful();

        $this->assertSame(PlatformPublishStatus::Published, $first->fresh()->tiktok_status);
        $this->assertSame(PlatformPublishStatus::Publishing, $second->fresh()->tiktok_status);

        // Quá publish_timeout_minutes vẫn chưa xong -> thất bại
        $this->travel(60)->minutes();
        $this->artisan('social:sweep')->assertSuccessful();
        $this->assertSame(PlatformPublishStatus::Failed, $second->fresh()->tiktok_status);
        $this->assertSame('TIKTOK_TIMEOUT', SocialPublishJob::query()->where('external_id', 'p_b')->value('error_code'));
    }

    public function test_cannot_disconnect_while_sending_but_can_connect_more(): void
    {
        $user = $this->buyer(credit: 5000);
        $account = $this->connectTikTok($user);
        $post = $this->pendingPost($user);
        $this->fakeTikTok(['/post/publish/content/init/' => self::tiktokOk(['publish_id' => 'p_c'])]);
        $this->publish($user, $post)->assertOk();

        $this->actingAs($user)->deleteJson('/api/v1/social/tiktok/accounts/'.$account->id)
            ->assertStatus(409)->assertJsonPath('code', 'TIKTOK_PUBLISHING_IN_PROGRESS');
        // Kết nối thêm / kết nối lại không thay tài khoản đang gửi nên không bị chặn
        $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/authorize', [])->assertOk();
    }

    // ---- Nhiều tài khoản TikTok ----

    public function test_connecting_another_account_adds_row_and_reconnecting_updates_it(): void
    {
        $user = $this->buyer();
        $connect = function (string $openId, string $username) use ($user) {
            $this->fakeTikTok([
                '/oauth/token/' => self::tokenResponse(['open_id' => $openId, 'access_token' => 'access-'.$openId]),
                '/user/info/*' => self::userInfoResponse(['open_id' => $openId, 'username' => $username]),
            ]);
            $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/callback', ['code' => 'c', 'state' => $this->authorizeState($user)])
                ->assertJsonPath('data.status', 'connected');
        };

        $connect('open-a', 'shopa');
        $connect('open-b', 'shopb');
        $connect('open-a', 'shopa2');

        $accounts = $user->tiktokConnections()->orderBy('id')->get();
        $this->assertSame(['open-a', 'open-b'], $accounts->pluck('open_id')->all());
        $this->assertSame('shopa2', $accounts[0]->username);
        $this->assertSame('access-open-a', $accounts[0]->access_token);

        $this->actingAs($user)->getJson('/api/v1/social/accounts')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $accounts[0]->id)
            ->assertJsonPath('data.1.username', 'shopb');
    }

    public function test_account_limit(): void
    {
        config(['services.tiktok.max_accounts' => 2]);
        $user = $this->buyer();
        $this->connectTikTok($user, ['open_id' => 'open-a']);
        $lost = $this->connectTikTok($user, ['open_id' => 'open-b', 'connection_status' => TikTokConnectionStatus::Expired]);

        // Đủ số nhưng có tài khoản mất kết nối: vẫn cho ủy quyền (để kết nối lại); tài khoản mới thì không lưu
        $state = $this->authorizeState($user);
        $this->fakeTikTok([
            '/oauth/token/' => self::tokenResponse(['open_id' => 'open-c']),
            '/oauth/revoke/' => Http::response(),
        ]);
        $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/callback', ['code' => 'c', 'state' => $state])
            ->assertJsonPath('data.reason', 'limit_reached');
        $this->assertSame(2, $user->tiktokConnections()->count());
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/oauth/revoke/') && $request['token'] === 'access-new');

        // Đủ số và đều đang dùng được: không cho bắt đầu ủy quyền
        $lost->update(['connection_status' => TikTokConnectionStatus::Active]);
        $this->actingAs($user)->postJson('/api/v1/social/tiktok/oauth/authorize', [])
            ->assertStatus(409)->assertJsonPath('code', 'TIKTOK_ACCOUNT_LIMIT');
    }

    public function test_publish_to_several_accounts_then_retry_only_the_failed_one(): void
    {
        $user = $this->buyer(credit: 5000);
        $a = $this->connectTikTok($user, ['open_id' => 'open-a', 'username' => 'shopa']);
        $b = $this->connectTikTok($user, ['open_id' => 'open-b', 'username' => 'shopb']);
        $post = $this->pendingPost($user);

        // Nhiều tài khoản: phải chọn
        $this->publish($user, $post)->assertStatus(422)->assertJsonValidationErrors('tiktok_account_ids');
        $this->publish($user, $post, ['tiktok'], [$a->id, 999999])->assertStatus(422)->assertJsonValidationErrors('tiktok_account_ids');

        $this->fakeTikTok(['/post/publish/content/init/' => Http::sequence()
            ->push(self::tiktokOk(['publish_id' => 'p_a']))
            ->push(self::tiktokError('spam_risk_too_many_posts'), 403)
            ->push(self::tiktokOk(['publish_id' => 'p_b2']))]);

        $this->publish($user, $post, ['tiktok'], [$a->id, $b->id])
            ->assertOk()
            ->assertJsonPath('data.platforms.tiktok.status', 'publishing')
            ->assertJsonPath('data.platforms.tiktok.accounts.0.name', '@shopa')
            ->assertJsonPath('data.platforms.tiktok.accounts.0.status', 'publishing')
            ->assertJsonPath('data.platforms.tiktok.accounts.1.name', '@shopb')
            ->assertJsonPath('data.platforms.tiktok.accounts.1.status', 'failed');
        $this->assertSame(2, SocialPublishJob::query()->count());

        // A xong, B lỗi: nền tảng lỗi, bài về chờ gửi
        $this->sendWebhook('post.publish.inbox_delivered', ['publish_id' => 'p_a'], 'open-a')->assertOk();
        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.platforms.tiktok.status', 'failed')
            ->assertJsonPath('data.platforms.tiktok.error', 'Tài khoản TikTok đã đạt giới hạn gửi bài trong ngày, thử lại sau.')
            ->assertJsonPath('data.platforms.tiktok.accounts.0.status', 'published');

        // Không gửi lại vào A
        $this->publish($user, $post, ['tiktok'], [$a->id])->assertStatus(422)->assertJsonValidationErrors('tiktok_account_ids');

        // Gửi lại vào B
        $this->publish($user, $post, ['tiktok'], [$b->id])->assertOk()->assertJsonPath('data.platforms.tiktok.status', 'publishing');
        $this->sendWebhook('post.publish.inbox_delivered', ['publish_id' => 'p_b2'], 'open-b')->assertOk();

        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.platforms.tiktok.status', 'published')
            ->assertJsonCount(2, 'data.platforms.tiktok.accounts')
            ->assertJsonPath('data.platforms.tiktok.accounts.1.status', 'published');
    }

    public function test_lost_account_among_several_rejects_publish_with_account_id(): void
    {
        $user = $this->buyer(credit: 5000);
        $a = $this->connectTikTok($user, ['open_id' => 'open-a']);
        $b = $this->connectTikTok($user, ['open_id' => 'open-b', 'username' => 'shopb', 'connection_status' => TikTokConnectionStatus::Expired]);
        $post = $this->pendingPost($user);

        $this->publish($user, $post, ['tiktok'], [$a->id, $b->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'TIKTOK_NOT_CONNECTED')
            ->assertJsonPath('context.reason', 'expired')
            ->assertJsonPath('context.account_id', $b->id);
        $this->assertSame(0, SocialPublishJob::query()->count());
    }

    // ---- Webhook ----

    public function test_webhook_signature_failure_and_authorization_removed(): void
    {
        $user = $this->buyer();
        $account = $this->connectTikTok($user);

        $this->sendWebhook('authorization.removed', [], $account->open_id, 't=1,s=bad')->assertStatus(401);
        $this->sendWebhook('authorization.removed', [], $account->open_id)->assertOk();

        $account->refresh();
        $this->assertSame(TikTokConnectionStatus::Revoked, $account->connection_status);
        $this->assertNull($account->access_token);
        $this->actingAs($user)->getJson('/api/v1/social/accounts')->assertJsonPath('data.0.connection.status', 'revoked');
    }

    public function test_webhook_failed_event_and_unknown_publish_id(): void
    {
        $user = $this->buyer(credit: 5000);
        $this->connectTikTok($user);
        $post = $this->pendingPost($user);
        $this->fakeTikTok(['/post/publish/content/init/' => self::tiktokOk(['publish_id' => 'p_d'])]);
        $this->publish($user, $post)->assertOk();

        $this->sendWebhook('post.publish.failed', ['publish_id' => 'unknown'])->assertNotFound();
        $this->sendWebhook('post.publish.failed', ['publish_id' => 'p_d', 'reason' => 'photo_pull_failed'])->assertOk();

        $this->actingAs($user)->getJson("/api/v1/social/posts/{$post->id}")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.platforms.tiktok.error', 'TikTok không tải được ảnh, vui lòng thử lại.');
    }
}
