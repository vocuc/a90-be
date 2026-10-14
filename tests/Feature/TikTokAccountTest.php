<?php

namespace Tests\Feature;

use App\Enums\TikTokAccountStatus;
use App\Enums\TikTokPostStatus;
use App\Models\TikTokAccount;
use App\Models\TikTokPost;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTikTok;
use Tests\TestCase;

class TikTokAccountTest extends TestCase
{
    use InteractsWithTikTok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTikTok();
    }

    private function authorizeState($user, ?string $redirect = '/tiktok/post/123'): string
    {
        $url = $this->actingAs($user)->postJson('/api/v1/tiktok/oauth/authorize', ['redirect' => $redirect])
            ->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.tiktok.com/v2/auth/authorize/?', $url);
        $this->assertSame('client-key', $query['client_key']);
        $this->assertSame('user.info.basic,video.publish', $query['scope']);
        $this->assertSame('https://api.test/api/v1/tiktok/oauth/callback', $query['redirect_uri']);
        $this->assertGreaterThanOrEqual(32, strlen($query['state']));

        return $query['state'];
    }

    private function fakeTokenExchange(string $scope = 'user.info.basic,video.publish', string $openId = 'open-abc'): void
    {
        $this->fakeTikTok([
            '/oauth/token/' => Http::response([
                'open_id' => $openId, 'scope' => $scope, 'access_token' => 'act.new', 'expires_in' => 86400,
                'refresh_token' => 'rft.new', 'refresh_expires_in' => 31536000, 'token_type' => 'Bearer',
            ]),
            '/user/info/*' => Http::response(['data' => ['user' => [
                'open_id' => $openId, 'union_id' => 'union-1', 'avatar_url' => 'https://p16.test/a.jpg', 'display_name' => 'Shop A',
            ]], 'error' => ['code' => 'ok']]),
            '/oauth/revoke/' => Http::response([]),
        ]);
    }

    public function test_oauth_flow_stores_encrypted_tokens_and_redirects_back(): void
    {
        $user = $this->buyer();
        $state = $this->authorizeState($user);
        $this->fakeTokenExchange();

        $this->get("/api/v1/tiktok/oauth/callback?code=abc&state={$state}&scopes=user.info.basic,video.publish")
            ->assertRedirect('https://app.test/tiktok/post/123?tiktok=connected');

        $account = TikTokAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('open-abc', $account->open_id);
        $this->assertSame('act.new', $account->access_token);
        $this->assertSame('Shop A', $account->display_name);
        $this->assertSame(TikTokAccountStatus::Active, $account->status);
        // Token mã hoá trong DB
        $raw = DB::table('tiktok_accounts')->where('id', $account->id)->first();
        $this->assertStringNotContainsString('act.new', $raw->access_token);
        $this->assertStringNotContainsString('rft.new', $raw->refresh_token);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::TT_API.'/oauth/token/'
            && $r['code'] === 'abc' && $r['grant_type'] === 'authorization_code' && $r['client_secret'] === 'client-secret');

        // state chỉ dùng 1 lần
        $this->get("/api/v1/tiktok/oauth/callback?code=abc&state={$state}")
            ->assertRedirect('https://app.test/tiktok?tiktok=error&reason=state_invalid');
    }

    public function test_callback_errors_redirect_with_reason(): void
    {
        $user = $this->buyer();

        $state = $this->authorizeState($user);
        $this->get("/api/v1/tiktok/oauth/callback?error=access_denied&state={$state}")
            ->assertRedirect('https://app.test/tiktok/post/123?tiktok=error&reason=access_denied');

        $state = $this->authorizeState($user);
        $this->fakeTokenExchange(scope: 'user.info.basic');
        $this->get("/api/v1/tiktok/oauth/callback?code=abc&state={$state}")
            ->assertRedirect('https://app.test/tiktok/post/123?tiktok=error&reason=scope_missing');
        $this->assertSame(0, TikTokAccount::query()->count());

        $this->get('/api/v1/tiktok/oauth/callback?code=abc&state=unknown')
            ->assertRedirect('https://app.test/tiktok?tiktok=error&reason=state_invalid');
    }

    public function test_token_exchange_failure_is_server_error(): void
    {
        $user = $this->buyer();
        $state = $this->authorizeState($user);
        $this->fakeTikTok(['/oauth/token/' => Http::response(['error' => 'invalid_grant', 'error_description' => 'bad code'], 400)]);

        $this->get("/api/v1/tiktok/oauth/callback?code=abc&state={$state}")
            ->assertRedirect('https://app.test/tiktok/post/123?tiktok=error&reason=server_error');
    }

    public function test_redirect_must_be_relative_path(): void
    {
        $user = $this->buyer();
        foreach (['https://evil.test/x', '//evil.test', '/\\evil.test', 'tiktok'] as $redirect) {
            $state = $this->authorizeState($user, $redirect);
            $this->get("/api/v1/tiktok/oauth/callback?error=access_denied&state={$state}")
                ->assertRedirect('https://app.test/tiktok?tiktok=error&reason=access_denied');
        }
    }

    public function test_connecting_another_tiktok_account_revokes_the_old_one(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user, ['open_id' => 'open-old', 'access_token' => 'act.old']);
        $state = $this->authorizeState($user);
        $this->fakeTokenExchange(openId: 'open-new');

        $this->get("/api/v1/tiktok/oauth/callback?code=abc&state={$state}")->assertRedirect();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::TT_API.'/oauth/revoke/' && $r['token'] === 'act.old');
        $this->assertSame(1, TikTokAccount::query()->count());
        $this->assertSame('open-new', TikTokAccount::query()->value('open_id'));
    }

    public function test_account_status_when_not_connected(): void
    {
        $this->actingAs($this->buyer())->getJson('/api/v1/tiktok/account')
            ->assertOk()
            ->assertExactJson(['data' => ['connected' => false, 'reason' => 'not_connected', 'account' => null, 'creator' => null]]);
    }

    public function test_account_status_fetches_creator_info_every_time(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user, ['username' => null]);
        $this->fakeTikTok(['/post/publish/creator_info/query/' => Http::response(self::creatorInfoResponse())]);

        $this->actingAs($user)->getJson('/api/v1/tiktok/account')
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.account.username', 'a51.vn')
            ->assertJsonPath('data.creator.privacy_level_options', ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'])
            ->assertJsonPath('data.creator.can_post', true)
            ->assertJsonMissingPath('data.account.access_token');

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer access-'.$user->id));
        $this->assertSame('a51.vn', $user->tiktokAccount()->value('username'));
    }

    public function test_expiring_access_token_is_refreshed_and_new_refresh_token_saved(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user, ['access_token_expires_at' => now()->addMinute()]);
        $this->fakeTikTok([
            '/oauth/token/' => Http::response([
                'open_id' => 'open-1', 'scope' => 'user.info.basic,video.publish', 'access_token' => 'act.2', 'expires_in' => 86400,
                'refresh_token' => 'rft.2', 'refresh_expires_in' => 31536000,
            ]),
            '/post/publish/creator_info/query/' => Http::response(self::creatorInfoResponse()),
        ]);

        $this->actingAs($user)->getJson('/api/v1/tiktok/account')->assertOk()->assertJsonPath('data.connected', true);

        $account = $user->tiktokAccount()->first();
        $this->assertSame('act.2', $account->access_token);
        $this->assertSame('rft.2', $account->refresh_token);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'creator_info') && $r->hasHeader('Authorization', 'Bearer act.2'));
    }

    public function test_invalid_refresh_marks_account_expired(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user);
        $this->fakeTikTok([
            '/post/publish/creator_info/query/' => Http::response(self::tiktokError('access_token_invalid'), 401),
            '/oauth/token/' => Http::response(['error' => 'invalid_grant', 'error_description' => 'expired'], 400),
        ]);

        $this->actingAs($user)->getJson('/api/v1/tiktok/account')
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.reason', 'expired')
            ->assertJsonPath('data.account.username', 'a51.vn');

        $this->assertSame(TikTokAccountStatus::Expired, $user->tiktokAccount()->first()->status);
    }

    public function test_creator_blocked_from_posting_returns_can_post_false(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user);
        $this->fakeTikTok(['/post/publish/creator_info/query/' => Http::response(self::tiktokError('spam_risk_too_many_posts'), 403)]);

        $this->actingAs($user)->getJson('/api/v1/tiktok/account')
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.creator.can_post', false)
            ->assertJsonPath('data.creator.cannot_post_reason.code', 'spam_risk_too_many_posts');
    }

    public function test_tiktok_outage_returns_502_not_disconnected(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user);
        $this->fakeTikTok(['/post/publish/creator_info/query/' => Http::response('oops', 503)]);

        $this->actingAs($user)->getJson('/api/v1/tiktok/account')
            ->assertStatus(502)
            ->assertJsonPath('code', 'TIKTOK_UNAVAILABLE');
        $this->assertSame(TikTokAccountStatus::Active, $user->tiktokAccount()->first()->status);
    }

    public function test_disconnect_revokes_token(): void
    {
        $user = $this->buyer();
        $this->connectTikTok($user);
        $this->fakeTikTok(['/oauth/revoke/' => Http::response([])]);

        $this->actingAs($user)->deleteJson('/api/v1/tiktok/account')->assertNoContent();

        $account = $user->tiktokAccount()->first();
        $this->assertSame(TikTokAccountStatus::Revoked, $account->status);
        $this->assertNull($account->access_token);
        $this->assertNull($account->refresh_token);
        $this->actingAs($user)->getJson('/api/v1/tiktok/account')->assertJsonPath('data.reason', 'revoked');
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertJsonPath('data.tiktok', ['connected' => false, 'username' => 'a51.vn']);
    }

    public function test_disconnect_blocked_while_a_post_is_publishing(): void
    {
        $user = $this->buyer();
        $account = $this->connectTikTok($user);
        TikTokPost::query()->create([
            'user_id' => $user->id, 'status' => TikTokPostStatus::Publishing, 'source' => 'existing',
            'idempotency_key' => 'k', 'tiktok_account_id' => $account->id,
        ]);

        $this->actingAs($user)->deleteJson('/api/v1/tiktok/account')
            ->assertStatus(409)
            ->assertJsonPath('code', 'TIKTOK_PUBLISHING_IN_PROGRESS');
    }

    public function test_webhook_authorization_removed_revokes_every_linked_account(): void
    {
        $a = $this->connectTikTok($this->buyer(), ['open_id' => 'open-shared']);
        $b = $this->connectTikTok($this->buyer(), ['open_id' => 'open-shared']);

        $this->sendWebhook('authorization.removed', ['reason' => 1], 'open-shared')->assertOk();

        foreach ([$a, $b] as $account) {
            $this->assertSame(TikTokAccountStatus::Revoked, $account->fresh()->status);
            $this->assertNull($account->fresh()->access_token);
        }
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $account = $this->connectTikTok($this->buyer(), ['open_id' => 'open-x']);

        $this->sendWebhook('authorization.removed', [], 'open-x', 't='.time().',s=deadbeef')->assertStatus(401);
        $this->sendWebhook('authorization.removed', [], 'open-x', 't='.(time() - 3600).',s=x')->assertStatus(401);

        $this->assertSame(TikTokAccountStatus::Active, $account->fresh()->status);
    }
}
