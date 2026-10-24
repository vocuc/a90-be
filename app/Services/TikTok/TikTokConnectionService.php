<?php

namespace App\Services\TikTok;

use App\Enums\OutputStatus;
use App\Enums\PublishDriver;
use App\Enums\SocialPlatform;
use App\Enums\TikTokConnectionStatus;
use App\Exceptions\BusinessException;
use App\Models\PublishAccount;
use App\Models\SocialPublishJob;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Người dùng tự kết nối TikTok (OAuth Login Kit), lưu thành publish_accounts driver api. Xem note/dang-tiktok-qua-api.txt.
 * TikTok trả người dùng về trang frontend (TIKTOK_REDIRECT_URI, vd https://a51.vn/mxh/tiktok/author?code=&state=),
 * frontend gửi code + state cho backend (callback) để đổi lấy token.
 */
class TikTokConnectionService
{
    private const STATE_TTL_MINUTES = 10;

    /** Link avatar TikTok là link CDN có ký hạn (x-expires): cache tối đa 1 ngày, trả lại trước hạn 1 giờ. */
    private const AVATAR_CACHE_MAX_SECONDS = 86400;

    private const AVATAR_EXPIRES_MARGIN_SECONDS = 3600;

    /** Gọi user/info lỗi: dùng tạm avatar đã lưu, thử lại sau khoảng này. */
    private const AVATAR_RETRY_SECONDS = 300;

    public const DEFAULT_REDIRECT = '/posts';

    public function __construct(
        private TikTokClient $client,
        private TikTokTokenManager $tokens,
        private AuditLogger $audit,
    ) {}

    /** URL ủy quyền TikTok; state dùng 1 lần, sống 10 phút, gắn với người dùng + trang quay về. */
    public function authorizeUrl(User $user, ?string $redirect): string
    {
        if ($account = $user->tiktokConnection) {
            $this->assertNotPublishing($account);
        }

        $state = Str::random(48);
        Cache::put(self::stateKey($state), [
            'user_id' => $user->id,
            'redirect' => self::safeRedirect($redirect),
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->client->authorizeUrl($state);
    }

    /**
     * Frontend gửi lên các tham số TikTok trả về trang redirect.
     *
     * @param  array{code?: ?string, state?: ?string, error?: ?string}  $params
     * @return array{status: string, reason: ?string, redirect: string}  status: connected | error
     */
    public function callback(User $user, array $params): array
    {
        $state = (string) ($params['state'] ?? '');
        // pull: xoá ngay để link cũ không dùng lại được
        $saved = $state !== '' ? Cache::pull(self::stateKey($state)) : null;
        // state của người khác (link ủy quyền bị gửi cho người khác mở) cũng coi là không hợp lệ
        if (! is_array($saved) || $saved['user_id'] !== $user->id) {
            return self::result('error', 'state_invalid', self::DEFAULT_REDIRECT);
        }

        $fail = fn (string $reason) => self::result('error', $reason, $saved['redirect']);

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

        if (! PublishAccount::hasRequiredScope($token['scope'] ?? null)) {
            return $fail('scope_missing');
        }

        try {
            $info = $this->client->userInfo($token['access_token']);
        } catch (TikTokApiException|BusinessException) {
            $info = []; // chỉ để hiển thị
        }

        $this->connect($user, $token, $info);

        return self::result('connected', null, $saved['redirect']);
    }

    /** Huỷ kết nối: thu hồi token ở TikTok rồi xoá dòng api (lần đăng sau dùng worker nếu admin đã gắn). */
    public function disconnect(User $user): void
    {
        $account = $user->tiktokConnection;
        if (! $account) {
            return;
        }
        $this->assertNotPublishing($account);

        $this->tokens->revoke($account);
        Cache::forget(self::avatarKey($account));
        $this->audit->log('tiktok.disconnect', $account, ['open_id' => $account->open_id], $user->id);
        $account->delete();
    }

    /** Webhook authorization.removed: người dùng gỡ quyền A51 trong app TikTok. */
    public function authorizationRemoved(string $openId): void
    {
        PublishAccount::query()
            ->where('driver', PublishDriver::Api)
            ->where('open_id', $openId)
            ->get()
            ->each(fn (PublishAccount $account) => $account->update([
                'access_token' => null,
                'refresh_token' => null,
                'connection_status' => TikTokConnectionStatus::Revoked,
                'last_error' => ['code' => 'authorization_removed', 'message' => 'Người dùng đã gỡ quyền A51 trên TikTok.'],
            ]));
    }

    /** Thông tin kết nối trả cho frontend (không có token). */
    public function connection(PublishAccount $account): array
    {
        $avatarUrl = $this->avatarUrl($account);

        return [
            'status' => $account->connectionReason() ?? 'active',
            'avatar_url' => $avatarUrl,
            'connected_at' => $account->connected_at,
        ];
    }

    /**
     * Avatar lưu lúc kết nối sẽ hết hạn: gọi lại user/info (user.info.basic) lấy link mới, cache tới gần x-expires.
     * Mất kết nối / TikTok lỗi thì trả avatar đã lưu (có thể đã hết hạn, frontend tự hiện chữ cái đầu).
     */
    private function avatarUrl(PublishAccount $account): ?string
    {
        if (! $account->isUsable()) {
            return $account->avatar_url;
        }

        $key = self::avatarKey($account);
        // Cache lưu '' khi không có avatar để phân biệt với chưa cache
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached !== '' ? $cached : null;
        }

        try {
            $url = $this->tokens->call($account, fn (string $token) => $this->client->userInfo($token))['avatar_url'] ?? null;
            $ttl = self::avatarTtl($url);
        } catch (TikTokApiException|BusinessException) {
            $url = $account->avatar_url;
            $ttl = self::AVATAR_RETRY_SECONDS;
        }

        Cache::put($key, (string) $url, $ttl);

        return $url ?: null;
    }

    private static function avatarTtl(?string $url): int
    {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
        $expires = $query['x-expires'] ?? null;
        if (! is_string($expires) || ! ctype_digit($expires)) {
            return self::AVATAR_CACHE_MAX_SECONDS;
        }

        $ttl = (int) $expires - time() - self::AVATAR_EXPIRES_MARGIN_SECONDS;

        return max(self::AVATAR_RETRY_SECONDS, min($ttl, self::AVATAR_CACHE_MAX_SECONDS));
    }

    private static function avatarKey(PublishAccount $account): string
    {
        return 'tiktok-avatar:'.$account->id;
    }

    private function connect(User $user, array $token, array $info): void
    {
        $account = $user->tiktokConnection;
        $sameAccount = $account && $account->open_id === $token['open_id'];
        $replaced = $account && ! $sameAccount;

        // Đổi sang tài khoản TikTok khác: thu hồi token cũ rồi thay thế
        if ($replaced && $account->access_token) {
            $this->tokens->revoke($account);
        }

        $displayName = $info['display_name'] ?? ($sameAccount ? $account->name : null);
        $username = $info['username'] ?? ($sameAccount ? $account->username : null);
        $attributes = [
            'name' => Str::limit($displayName ?: ($username ?: 'TikTok'), 100, ''),
            'username' => $username,
            'is_active' => true,
            'open_id' => $token['open_id'],
            'scope' => (string) $token['scope'],
            ...TikTokTokenManager::tokenAttributes($token),
            'avatar_url' => $info['avatar_url'] ?? ($sameAccount ? $account->avatar_url : null),
            'connection_status' => TikTokConnectionStatus::Active,
            'last_error' => null,
            'connected_at' => now(),
            'last_refreshed_at' => now(),
        ];

        $account = $account
            ? tap($account)->update($attributes)
            : PublishAccount::query()->create([
                'user_id' => $user->id,
                'platform' => SocialPlatform::Tiktok,
                'driver' => PublishDriver::Api,
                ...$attributes,
            ]);

        Cache::forget(self::avatarKey($account));
        $this->audit->log('tiktok.connect', $account, ['open_id' => $account->open_id, 'replaced' => $replaced], $user->id);
    }

    /** Job api đang chạy dùng token của tài khoản này: không đổi / huỷ kết nối giữa chừng. */
    private function assertNotPublishing(PublishAccount $account): void
    {
        $running = SocialPublishJob::query()
            ->where('publish_account_id', $account->id)
            ->whereIn('status', [OutputStatus::Queued, OutputStatus::Processing])
            ->exists();
        if ($running) {
            throw new BusinessException('TIKTOK_PUBLISHING_IN_PROGRESS', 'Đang có bài gửi lên TikTok, vui lòng chờ xong rồi đổi / huỷ kết nối.', 409);
        }
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

    private static function result(string $status, ?string $reason, string $redirect): array
    {
        return ['status' => $status, 'reason' => $reason, 'redirect' => $redirect];
    }

    private static function stateKey(string $state): string
    {
        return 'tiktok-oauth-state:'.hash('sha256', $state);
    }
}
