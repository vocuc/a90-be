<?php

namespace App\Services\TikTok;

use App\Enums\TikTokAccountStatus;
use App\Enums\TikTokPostStatus;
use App\Exceptions\BusinessException;
use App\Models\TikTokAccount;
use App\Models\TikTokPost;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ủy quyền tài khoản TikTok (OAuth Login Kit) và thông tin hồ sơ, xem note/uy-quyen-tiktok.txt.
 */
class TikTokAccountService
{
    private const STATE_TTL_MINUTES = 10;

    private const DEFAULT_REDIRECT = '/tiktok';

    public function __construct(
        private TikTokClient $client,
        private TikTokTokenManager $tokens,
        private AuditLogger $audit,
    ) {}

    /** URL ủy quyền TikTok; state dùng 1 lần, sống 10 phút, gắn với người dùng + trang quay về. */
    public function authorizeUrl(User $user, ?string $redirect): string
    {
        $state = Str::random(48);
        Cache::put(self::stateKey($state), [
            'user_id' => $user->id,
            'redirect' => self::safeRedirect($redirect),
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->client->authorizeUrl($state);
    }

    /**
     * TikTok gọi về sau khi người dùng đồng ý / từ chối. Luôn trả URL frontend để redirect 302.
     *
     * @param  array{code?: ?string, state?: ?string, error?: ?string}  $params
     */
    public function handleCallback(array $params): string
    {
        $state = (string) ($params['state'] ?? '');
        // pull: xoá ngay để link cũ không dùng lại được
        $saved = $state !== '' ? Cache::pull(self::stateKey($state)) : null;
        if (! is_array($saved) || ! ($user = User::query()->find($saved['user_id']))) {
            return $this->frontendUrl(self::DEFAULT_REDIRECT, ['tiktok' => 'error', 'reason' => 'state_invalid']);
        }

        $fail = fn (string $reason) => $this->frontendUrl($saved['redirect'], ['tiktok' => 'error', 'reason' => $reason]);

        if (! empty($params['error'])) {
            return $fail($params['error'] === 'access_denied' ? 'access_denied' : 'server_error');
        }
        if (empty($params['code'])) {
            return $fail('server_error');
        }

        try {
            $token = $this->client->exchangeCode($params['code']);
        } catch (TikTokApiException|BusinessException) {
            return $fail('server_error');
        }

        if (! TikTokAccount::hasRequiredScope($token['scope'] ?? null)) {
            return $fail('scope_missing');
        }

        try {
            $info = $this->client->userInfo($token['access_token']);
        } catch (TikTokApiException|BusinessException) {
            $info = []; // chỉ để hiển thị, GET /tiktok/account sẽ cập nhật sau
        }

        $this->connect($user, $token, $info);

        return $this->frontendUrl($saved['redirect'], ['tiktok' => 'connected']);
    }

    /**
     * Trạng thái cho GET /tiktok/account. Gọi user/info mỗi lần để kiểm tra token và cập nhật hồ sơ.
     */
    public function status(User $user): array
    {
        $account = $user->tiktokAccount;
        $reason = match (true) {
            ! $account => 'not_connected',
            $account->status === TikTokAccountStatus::Revoked => 'revoked',
            $account->status === TikTokAccountStatus::Expired => 'expired',
            ! TikTokAccount::hasRequiredScope($account->scope) => 'scope_missing',
            default => null,
        };

        if ($reason === null) {
            try {
                $this->refreshProfile($account);
            } catch (BusinessException $e) {
                if ($e->errorCode !== 'TIKTOK_NOT_CONNECTED') {
                    throw $e;
                }
                $reason = $e->context['reason'] ?? 'expired';
                $account->refresh();
            }
        }

        if ($reason !== null) {
            return [
                'connected' => false,
                'reason' => $reason,
                // Còn thông tin cũ: frontend hiện "Ủy quyền lại @username"
                'account' => $account && $reason !== 'not_connected' ? self::accountSummary($account) : null,
            ];
        }

        return [
            'connected' => true,
            'reason' => null,
            'account' => self::accountSummary($account),
        ];
    }

    /**
     * user/info: cập nhật cache username / display_name / avatar. TikTok từ chối (vd thiếu user.info.profile) -> giữ thông tin cũ.
     * Không dùng được tài khoản -> TIKTOK_NOT_CONNECTED (context.reason); TikTok lỗi mạng / 5xx -> TIKTOK_UNAVAILABLE.
     */
    private function refreshProfile(TikTokAccount $account): void
    {
        try {
            $info = $this->tokens->call($account, fn (string $token) => $this->client->userInfo($token));
        } catch (TikTokApiException $e) {
            $account->update(['last_error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);

            return;
        }

        $account->update(array_filter([
            'username' => $info['username'] ?? null,
            'display_name' => $info['display_name'] ?? null,
            'avatar_url' => $info['avatar_url'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /** Huỷ kết nối: thu hồi token ở TikTok, xoá token, status revoked. */
    public function disconnect(User $user): void
    {
        $account = $user->tiktokAccount;
        if (! $account) {
            return;
        }

        $publishing = TikTokPost::query()
            ->where('tiktok_account_id', $account->id)
            ->where('status', TikTokPostStatus::Publishing)
            ->exists();
        if ($publishing) {
            throw new BusinessException('TIKTOK_PUBLISHING_IN_PROGRESS', 'Đang có bài đăng lên TikTok, vui lòng chờ đăng xong rồi huỷ kết nối.', 409);
        }

        $this->tokens->revoke($account);
        $this->audit->log('tiktok.disconnect', $account, ['open_id' => $account->open_id], $user->id);
    }

    /** Webhook authorization.removed: người dùng gỡ quyền A51 trong app TikTok. */
    public function authorizationRemoved(string $openId): void
    {
        TikTokAccount::query()->where('open_id', $openId)->get()
            ->each(fn (TikTokAccount $account) => $account->update([
                'access_token' => null,
                'refresh_token' => null,
                'status' => TikTokAccountStatus::Revoked,
                'last_error' => ['code' => 'authorization_removed', 'message' => 'Người dùng đã gỡ quyền A51 trên TikTok.'],
            ]));
    }

    private function connect(User $user, array $token, array $info): void
    {
        $account = $user->tiktokAccount;
        $sameAccount = $account && $account->open_id === $token['open_id'];
        $replaced = $account && ! $sameAccount;

        // Đổi sang tài khoản TikTok khác: thu hồi token cũ rồi thay thế
        if ($replaced && $account->access_token) {
            $this->tokens->revoke($account);
        }

        $attributes = [
            'open_id' => $token['open_id'],
            'union_id' => $info['union_id'] ?? null,
            'scope' => (string) $token['scope'],
            ...TikTokTokenManager::tokenAttributes($token),
            'display_name' => $info['display_name'] ?? ($sameAccount ? $account->display_name : null),
            'username' => $info['username'] ?? ($sameAccount ? $account->username : null),
            'avatar_url' => $info['avatar_url'] ?? ($sameAccount ? $account->avatar_url : null),
            'status' => TikTokAccountStatus::Active,
            'last_error' => null,
            'connected_at' => now(),
            'last_refreshed_at' => now(),
        ];

        $account = $account
            ? tap($account)->update($attributes)
            : TikTokAccount::query()->create(['user_id' => $user->id, ...$attributes]);

        $this->audit->log('tiktok.connect', $account, ['open_id' => $account->open_id, 'replaced' => $replaced], $user->id);
    }

    private static function accountSummary(TikTokAccount $account): array
    {
        return [
            'username' => $account->username,
            'nickname' => $account->display_name,
            'avatar_url' => $account->avatar_url,
            'connected_at' => $account->connected_at,
        ];
    }

    /** Chỉ nhận đường dẫn tương đối "/..." (không "//", không scheme / host) để tránh open redirect. */
    public static function safeRedirect(?string $redirect): string
    {
        if (! is_string($redirect) || ! str_starts_with($redirect, '/') || str_starts_with($redirect, '//')
            || str_contains($redirect, '\\') || preg_match('/[\x00-\x1F\x7F]/', $redirect)
            || parse_url($redirect, PHP_URL_SCHEME) !== null || parse_url($redirect, PHP_URL_HOST) !== null
            || strlen($redirect) > 500) {
            return self::DEFAULT_REDIRECT;
        }

        return $redirect;
    }

    private function frontendUrl(string $redirect, array $query): string
    {
        return config('services.tiktok.frontend_url').$redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query($query);
    }

    private static function stateKey(string $state): string
    {
        return 'tiktok-oauth-state:'.hash('sha256', $state);
    }
}
