<?php

namespace App\Services\TikTok;

use App\Enums\TikTokAccountStatus;
use App\Exceptions\BusinessException;
use App\Models\TikTokAccount;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Cấp access token còn hạn cho mọi lời gọi TikTok (user/info, đăng bài), tự làm mới khi sắp hết hạn.
 */
class TikTokTokenManager
{
    /** Làm mới trước khi access token còn dưới khoảng này. */
    private const REFRESH_BEFORE_SECONDS = 300;

    public function __construct(private TikTokClient $client) {}

    /**
     * Chạy $call với access token; TikTok báo token không hợp lệ thì làm mới và thử lại 1 lần.
     * Tài khoản không dùng được (chưa kết nối / expired / revoked / làm mới thất bại) -> TIKTOK_NOT_CONNECTED.
     *
     * @template T
     *
     * @param  callable(string): T  $call
     * @return T
     */
    public function call(TikTokAccount $account, callable $call): mixed
    {
        try {
            return $call($this->accessToken($account));
        } catch (TikTokApiException $e) {
            if (! self::isInvalidToken($e)) {
                throw $e;
            }
        }

        $this->refresh($account, force: true);

        try {
            return $call($account->access_token);
        } catch (TikTokApiException $e) {
            if (self::isInvalidToken($e)) {
                $this->markExpired($account, $e);
                throw BusinessException::tiktokNotConnected();
            }
            throw $e;
        }
    }

    public function accessToken(TikTokAccount $account): string
    {
        if (! $account->isUsable()) {
            throw BusinessException::tiktokNotConnected();
        }

        if (! $account->access_token || ! $account->access_token_expires_at
            || $account->access_token_expires_at->lt(now()->addSeconds(self::REFRESH_BEFORE_SECONDS))) {
            $this->refresh($account);
        }

        return $account->access_token;
    }

    /**
     * Khoá theo tài khoản: job nền và request người dùng làm mới cùng lúc sẽ làm refresh token cũ mất hiệu lực.
     * Lỗi token -> expired + TIKTOK_NOT_CONNECTED; lỗi mạng / 5xx -> giữ nguyên, TIKTOK_UNAVAILABLE.
     */
    public function refresh(TikTokAccount $account, bool $force = false): void
    {
        $lock = Cache::lock("tiktok-refresh:{$account->id}", 30);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            throw BusinessException::tiktokUnavailable();
        }

        try {
            $previousToken = $account->access_token;
            $account->refresh();
            if (! $account->isUsable()) {
                throw BusinessException::tiktokNotConnected();
            }
            // Request khác vừa làm mới xong trong lúc chờ khoá
            $refreshedMeanwhile = $account->access_token !== $previousToken;
            $stillValid = $account->access_token_expires_at?->gt(now()->addSeconds(self::REFRESH_BEFORE_SECONDS));
            if ($stillValid && (! $force || $refreshedMeanwhile)) {
                return;
            }

            try {
                $token = $this->client->refresh($account->refresh_token);
            } catch (TikTokApiException $e) {
                $this->markExpired($account, $e);
                throw BusinessException::tiktokNotConnected();
            }

            $account->update([
                ...self::tokenAttributes($token),
                'last_refreshed_at' => now(),
                'last_error' => null,
            ]);
        } finally {
            $lock->release();
        }
    }

    /** Thu hồi token ở TikTok (lỗi vẫn bỏ qua) và xoá token phía A51. */
    public function revoke(TikTokAccount $account): void
    {
        if ($account->access_token) {
            try {
                $this->client->revoke($account->access_token);
            } catch (\Throwable) {
                // Thu hồi lỗi vẫn xoá token phía A51
            }
        }

        $account->update(['access_token' => null, 'refresh_token' => null, 'status' => TikTokAccountStatus::Revoked]);
    }

    public function markExpired(TikTokAccount $account, TikTokApiException $e): void
    {
        $account->update([
            'status' => TikTokAccountStatus::Expired,
            'last_error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
        ]);
    }

    /** Thuộc tính token từ phản hồi /oauth/token/ (đổi code hoặc làm mới). */
    public static function tokenAttributes(array $token): array
    {
        return [
            // Phản hồi làm mới có thể không kèm scope: giữ scope cũ
            ...(! empty($token['scope']) ? ['scope' => (string) $token['scope']] : []),
            'access_token' => $token['access_token'],
            'access_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 0)),
            'refresh_token' => $token['refresh_token'],
            'refresh_token_expires_at' => now()->addSeconds((int) ($token['refresh_expires_in'] ?? 0)),
        ];
    }

    private static function isInvalidToken(TikTokApiException $e): bool
    {
        return $e->httpStatus === 401 || $e->errorCode === 'access_token_invalid';
    }
}
