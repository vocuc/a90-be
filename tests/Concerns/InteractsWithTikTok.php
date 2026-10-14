<?php

namespace Tests\Concerns;

use App\Enums\TikTokAccountStatus;
use App\Models\TikTokAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

trait InteractsWithTikTok
{
    protected const TT_API = 'https://open.tiktokapis.com/v2';

    protected function setUpTikTok(): void
    {
        config([
            'services.tiktok.client_key' => 'client-key',
            'services.tiktok.client_secret' => 'client-secret',
            'services.tiktok.redirect_uri' => 'https://api.test/api/v1/tiktok/oauth/callback',
            'services.tiktok.frontend_url' => 'https://app.test',
            'services.tiktok.media_disk' => 'tiktok_media',
            'services.tiktok.media_url' => 'https://media.a51.test',
        ]);
        Storage::fake('tiktok_media');
    }

    protected function connectTikTok(User $user, array $attributes = []): TikTokAccount
    {
        return TikTokAccount::query()->create(array_merge([
            'user_id' => $user->id,
            'open_id' => 'open-'.$user->id,
            'scope' => 'user.info.basic,user.info.profile,video.upload',
            'access_token' => 'access-'.$user->id,
            'access_token_expires_at' => now()->addHours(20),
            'refresh_token' => 'refresh-'.$user->id,
            'refresh_token_expires_at' => now()->addDays(300),
            'display_name' => 'A51 Official',
            'username' => 'a51.vn',
            'status' => TikTokAccountStatus::Active,
            'connected_at' => now(),
        ], $attributes));
    }

    protected static function userInfoResponse(array $user = []): array
    {
        return [
            'data' => ['user' => array_merge([
                'open_id' => 'open-1',
                'union_id' => 'union-1',
                'avatar_url' => 'https://p16.tiktokcdn.test/avatar.jpg',
                'display_name' => 'A51 Official',
                'username' => 'a51.vn',
            ], $user)],
            'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log'],
        ];
    }

    protected static function tiktokError(string $code): array
    {
        return ['data' => (object) [], 'error' => ['code' => $code, 'message' => $code, 'log_id' => 'log']];
    }

    /** Gửi webhook có chữ ký hợp lệ (content là chuỗi JSON như TikTok gửi). */
    protected function sendWebhook(string $event, array $content, string $openId = 'open-1', ?string $signature = null): TestResponse
    {
        $body = json_encode([
            'client_key' => 'client-key',
            'event' => $event,
            'create_time' => time(),
            'user_openid' => $openId,
            'content' => json_encode($content),
        ]);
        $timestamp = time();
        $signature ??= 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$body, 'client-secret');

        return $this->call('POST', '/api/v1/tiktok/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_TIKTOK_SIGNATURE' => $signature,
        ], $body);
    }

    protected function fakeTikTok(array $responses): void
    {
        Http::fake(collect($responses)->mapWithKeys(fn ($response, $path) => [self::TT_API.$path => $response])->all());
    }
}
