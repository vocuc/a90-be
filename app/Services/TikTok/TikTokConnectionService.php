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
 * Một tài khoản A51 kết nối được nhiều tài khoản TikTok (1 dòng / open_id, tối đa services.tiktok.max_accounts):
 * kết nối lại cùng tài khoản TikTok thì cập nhật dòng cũ, tài khoản khác thì thêm dòng (note/uy-quyen-nhieu-tiktok.txt).
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

    /**
     * URL ủy quyền TikTok; state dùng 1 lần, sống 10 phút, gắn với người dùng + trang quay về.
     * Đủ số tài khoản tối đa mà tài khoản nào cũng đang dùng được -> 409 (không còn chỗ thêm, cũng không có gì để kết nối lại).
     */
    public function authorizeUrl(User $user, ?string $redirect): string
    {
        $accounts = $user->tiktokConnections()->get();
        if ($accounts->count() >= self::maxAccounts() && $accounts->every(fn (PublishAccount $account) => $account->isUsable())) {
            throw self::limitReached();
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
     * @return array{status: string, reason: ?string, redirect: string} status: connected | error
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

        // Tài khoản TikTok mới khi đã đủ số tối đa: không lưu, thu hồi token vừa cấp. Kiểm tra trước khi lấy thông tin
        // người dùng: không gọi TikTok thừa cho tài khoản sẽ bị từ chối
        $isNew = ! $user->tiktokConnections()->where('open_id', $token['open_id'])->exists();
        if ($isNew && $user->tiktokConnections()->count() >= self::maxAccounts()) {
            try {
                $this->client->revoke($token['access_token']);
            } catch (\Throwable) {
                // Không thu hồi được thì token tự hết hạn, A51 không lưu
            }

            return $fail('limit_reached');
        }

        try {
            $info = $this->client->userInfo($token['access_token']);
        } catch (TikTokApiException|BusinessException) {
            $info = []; // chỉ để hiển thị
        }

        $this->connect($user, $token, $info);

        return self::result('connected', null, $saved['redirect']);
    }

    /**
     * Huỷ kết nối một tài khoản TikTok: thu hồi token ở TikTok rồi xoá dòng api. Hết tài khoản api thì lần đăng sau dùng
     * worker nếu admin đã gắn. Không phải tài khoản của người dùng -> 404.
     */
    public function disconnect(User $user, int $accountId): void
    {
        $account = $user->tiktokConnections()->findOrFail($accountId);
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

    /** Kết nối lại cùng tài khoản TikTok (open_id) thì cập nhật dòng cũ, tài khoản mới thì thêm dòng. */
    private function connect(User $user, array $token, array $info): void
    {
        $account = $user->tiktokConnections()->where('open_id', $token['open_id'])->first();

        $displayName = $info['display_name'] ?? $account?->name;
        $username = $info['username'] ?? $account?->username;
        $attributes = [
            'name' => Str::limit($displayName ?: ($username ?: 'TikTok'), 100, ''),
            'username' => $username,
            'is_active' => true,
            'open_id' => $token['open_id'],
            'scope' => (string) $token['scope'],
            ...TikTokTokenManager::tokenAttributes($token),
            'avatar_url' => $info['avatar_url'] ?? $account?->avatar_url,
            'connection_status' => TikTokConnectionStatus::Active,
            'last_error' => null,
            'connected_at' => now(),
            'last_refreshed_at' => now(),
        ];

        $reconnected = (bool) $account;
        $account = $account
            ? tap($account)->update($attributes)
            : PublishAccount::query()->create([
                'user_id' => $user->id,
                'platform' => SocialPlatform::Tiktok,
                'driver' => PublishDriver::Api,
                ...$attributes,
            ]);

        Cache::forget(self::avatarKey($account));
        $this->audit->log('tiktok.connect', $account, ['open_id' => $account->open_id, 'reconnected' => $reconnected], $user->id);
    }

    public static function maxAccounts(): int
    {
        return max(1, (int) config('services.tiktok.max_accounts'));
    }

    private static function limitReached(): BusinessException
    {
        return new BusinessException(
            'TIKTOK_ACCOUNT_LIMIT',
            'Bạn đã kết nối tối đa '.self::maxAccounts().' tài khoản TikTok. Huỷ kết nối bớt một tài khoản để thêm tài khoản khác.',
            409,
        );
    }

    /** Job api đang chạy dùng token của tài khoản này: không huỷ kết nối giữa chừng. */
    private function assertNotPublishing(PublishAccount $account): void
    {
        $running = SocialPublishJob::query()
            ->where('publish_account_id', $account->id)
            ->whereIn('status', [OutputStatus::Queued, OutputStatus::Processing])
            ->exists();
        if ($running) {
            throw new BusinessException('TIKTOK_PUBLISHING_IN_PROGRESS', 'Đang có bài gửi lên tài khoản TikTok này, vui lòng chờ xong rồi huỷ kết nối.', 409);
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
