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
 * Ủy quyền tài khoản TikTok (OAuth Login Kit) và thông tin creator, xem note/uy-quyen-tiktok.txt.
 */
class TikTokAccountService
{
    private const STATE_TTL_MINUTES = 10;

    private const DEFAULT_REDIRECT = '/tiktok';

    /** Lỗi creator_info chặn đăng bài -> thông báo tiếng Việt. */
    private const CANNOT_POST = [
        'spam_risk_too_many_posts' => 'Tài khoản đã đạt giới hạn đăng bài trong ngày, thử lại sau.',
        'spam_risk_user_banned_from_posting' => 'Tài khoản TikTok này đang bị hạn chế đăng bài.',
        'reached_active_user_cap' => 'TikTok đang giới hạn số người dùng đăng qua A51, thử lại sau.',
    ];

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
            $info = []; // chỉ để hiển thị, creator_info sẽ cập nhật sau
        }

        $this->connect($user, $token, $info);

        return $this->frontendUrl($saved['redirect'], ['tiktok' => 'connected']);
    }

    /**
     * Trạng thái cho GET /tiktok/account. Gọi creator_info mỗi lần (TikTok yêu cầu dữ liệu mới).
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
                $creator = $this->creator($account);
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
                'creator' => null,
            ];
        }

        return [
            'connected' => true,
            'reason' => null,
            'account' => self::accountSummary($account),
            'creator' => $creator,
        ];
    }

    /**
     * creator_info/query, cập nhật cache username / display_name / avatar.
     * Không dùng được tài khoản -> TIKTOK_NOT_CONNECTED (context.reason); TikTok lỗi -> TIKTOK_UNAVAILABLE.
     */
    public function creator(TikTokAccount $account): array
    {
        try {
            $info = $this->tokens->call($account, fn (string $token) => $this->client->creatorInfo($token));
        } catch (TikTokApiException $e) {
            if ($e->errorCode === 'scope_not_authorized') {
                throw BusinessException::tiktokNotConnected('scope_missing');
            }
            $account->update(['last_error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
            if (isset(self::CANNOT_POST[$e->errorCode])) {
                return self::creatorPayload($account, [], ['code' => $e->errorCode, 'message' => self::CANNOT_POST[$e->errorCode]]);
            }
            throw BusinessException::tiktokUnavailable();
        }

        $account->update(array_filter([
            'username' => $info['creator_username'] ?? null,
            'display_name' => $info['creator_nickname'] ?? null,
            'avatar_url' => $info['creator_avatar_url'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        return self::creatorPayload($account, $info, null);
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
            'username' => $sameAccount ? $account->username : null,
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

    private static function creatorPayload(TikTokAccount $account, array $info, ?array $cannotPost): array
    {
        return [
            'avatar_url' => $info['creator_avatar_url'] ?? $account->avatar_url,
            'username' => $info['creator_username'] ?? $account->username,
            'nickname' => $info['creator_nickname'] ?? $account->display_name,
            'privacy_level_options' => array_values($info['privacy_level_options'] ?? []),
            'comment_disabled' => (bool) ($info['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($info['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($info['stitch_disabled'] ?? false),
            'max_video_post_duration_sec' => isset($info['max_video_post_duration_sec']) ? (int) $info['max_video_post_duration_sec'] : null,
            'can_post' => $cannotPost === null,
            'cannot_post_reason' => $cannotPost,
        ];
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
