<?php

namespace App\Services\TikTok;

use App\Exceptions\BusinessException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gọi TikTok Open API v2 (Login Kit + Content Posting API). Không ghi log token.
 * Lỗi mạng / 5xx -> BusinessException TIKTOK_UNAVAILABLE; TikTok từ chối -> TikTokApiException.
 */
class TikTokClient
{
    public const AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const API = 'https://open.tiktokapis.com/v2';

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'response_type' => 'code',
            'scope' => config('services.tiktok.scopes'),
            'redirect_uri' => config('services.tiktok.redirect_uri'),
            'state' => $state,
        ]);
    }

    /** @return array{open_id: string, scope: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int} */
    public function exchangeCode(string $code): array
    {
        return $this->oauth('/oauth/token/', [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.tiktok.redirect_uri'),
        ]);
    }

    /** TikTok có thể trả refresh_token mới: luôn lưu lại cả hai. */
    public function refresh(string $refreshToken): array
    {
        return $this->oauth('/oauth/token/', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function revoke(string $accessToken): void
    {
        $this->send(fn () => $this->http()->asForm()->post(self::API.'/oauth/revoke/', [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'token' => $accessToken,
        ]));
    }

    /** username cần scope user.info.profile. @return array{open_id?: string, union_id?: string, avatar_url?: string, display_name?: string, username?: string} */
    public function userInfo(string $accessToken): array
    {
        $response = $this->send(fn () => $this->http()->withToken($accessToken)
            ->get(self::API.'/user/info/', ['fields' => 'open_id,union_id,avatar_url,display_name,username']));

        return $this->data($response)['user'] ?? [];
    }

    /** Gửi ảnh vào hộp thư TikTok của người dùng (MEDIA_UPLOAD). @return string publish_id */
    public function initPhotoPost(string $accessToken, array $body): string
    {
        $response = $this->send(fn () => $this->http()->withToken($accessToken)->asJson()
            ->post(self::API.'/post/publish/content/init/', $body));

        return (string) ($this->data($response)['publish_id'] ?? '');
    }

    /** Header "TikTok-Signature: t=<unix>,s=<hex hmac_sha256("{t}.{body}", client_secret)>". */
    public function verifyWebhookSignature(?string $header, string $body): bool
    {
        $secret = (string) config('services.tiktok.client_secret');
        if (! $header || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['s'] ?? '';
        if (! ctype_digit($timestamp) || $signature === ''
            || abs(time() - (int) $timestamp) > config('services.tiktok.webhook_tolerance_seconds')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
    }

    private function oauth(string $path, array $params): array
    {
        $response = $this->send(fn () => $this->http()->asForm()->post(self::API.$path, [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            ...$params,
        ]));

        $json = $response->json() ?? [];
        // OAuth trả lỗi dạng { error, error_description } (có khi kèm HTTP 200)
        if (! empty($json['error']) || empty($json['access_token'])) {
            throw new TikTokApiException((string) ($json['error'] ?? 'invalid_response'), (string) ($json['error_description'] ?? ''), $response->status());
        }

        return $json;
    }

    /** Content / user API trả { data, error: { code, message, log_id } }, code "ok" là thành công. */
    private function data(Response $response): array
    {
        $json = $response->json() ?? [];
        $code = $json['error']['code'] ?? ($response->successful() ? 'ok' : 'unknown_error');
        if ($code !== 'ok' || ! $response->successful()) {
            throw new TikTokApiException((string) $code, (string) ($json['error']['message'] ?? ''), $response->status());
        }

        return $json['data'] ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)->connectTimeout(10);
    }

    /** @param  callable(): Response  $request */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw BusinessException::tiktokUnavailable();
        }

        if ($response->serverError()) {
            throw BusinessException::tiktokUnavailable();
        }

        return $response;
    }
}
