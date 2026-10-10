<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Xác thực id_token do Google Identity Services (nút "Đăng nhập bằng Google") cấp.
 * https://developers.google.com/identity/gsi/web/guides/verify-google-id-token
 */
class GoogleIdentity
{
    public const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public const KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const KEYS_CACHE_KEY = 'google.auth.keys';

    /**
     * @return array{sub: string, email: ?string, email_verified: bool, name: ?string}
     *
     * @throws UnexpectedValueException token sai chữ ký, hết hạn, sai issuer/audience
     */
    public function verify(string $idToken): array
    {
        $clientIds = config('services.google.client_ids');
        if (! $clientIds) {
            throw new UnexpectedValueException('Chưa cấu hình GOOGLE_CLIENT_ID.');
        }

        JWT::$leeway = 60;

        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys()));
        } catch (UnexpectedValueException $e) {
            // Google vừa xoay khoá -> kid chưa có trong cache, tải lại một lần
            if (! str_contains($e->getMessage(), '"kid"')) {
                throw $e;
            }
            Cache::forget(self::KEYS_CACHE_KEY);
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys()));
        }

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new UnexpectedValueException('Sai issuer.');
        }
        if (! in_array($claims['aud'] ?? null, $clientIds, true)) {
            throw new UnexpectedValueException('Sai audience.');
        }
        if (empty($claims['sub'])) {
            throw new UnexpectedValueException('Thiếu sub.');
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => isset($claims['email']) ? mb_strtolower((string) $claims['email']) : null,
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }

    private function keys(): array
    {
        // Google xoay khoá vài ngày một lần, cache 6 giờ là đủ an toàn
        return Cache::remember(self::KEYS_CACHE_KEY, now()->addHours(6), function () {
            try {
                return Http::timeout(10)->get(self::KEYS_URL)->throw()->json();
            } catch (Throwable $e) {
                throw new UnexpectedValueException('Không tải được khoá công khai của Google.', 0, $e);
            }
        });
    }
}
