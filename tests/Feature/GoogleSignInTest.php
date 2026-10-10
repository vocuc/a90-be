<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleIdentity;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    private const CLIENT_ID = 'test-client.apps.googleusercontent.com';

    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_ids' => [self::CLIENT_ID]]);
        Cache::flush();

        // Giả lập khoá công khai của Google bằng một cặp khoá RSA tự sinh.
        // PHP trên Windows thường không kèm openssl.cnf -> dùng một file cấu hình rỗng
        $config = tempnam(sys_get_temp_dir(), 'ossl');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        openssl_pkey_export($key, $this->privateKey, null, ['config' => $config]);
        @unlink($config);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        Http::fake([GoogleIdentity::KEYS_URL => Http::response(['keys' => [[
            'kty' => 'RSA', 'kid' => 'test-kid', 'use' => 'sig', 'alg' => 'RS256',
            'n' => $b64($rsa['n']), 'e' => $b64($rsa['e']),
        ]]])]);
    }

    private function idToken(array $claims = []): string
    {
        return JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'exp' => time() + 600,
            'sub' => '1098765432100',
            'email' => 'Google@Example.com',
            'email_verified' => true,
            'name' => 'Nguyễn Google',
            ...$claims,
        ], $this->privateKey, 'RS256', 'test-kid');
    }

    public function test_first_sign_in_creates_account_then_logs_in(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken()])
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Nguyễn Google')
            ->assertJsonPath('data.user.email', 'google@example.com')
            ->assertJsonPath('data.user.credit_balance', 0);

        // Người dùng đổi email Google -> vẫn nhận ra qua sub
        $token = $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken(['email' => 'new@example.com'])])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'google@example.com')
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(1, User::query()->count());
    }

    public function test_links_to_existing_account_with_same_email(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Người cũ',
            'email' => 'google@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken()])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Người cũ');

        // Mật khẩu cũ vẫn dùng được
        $this->postJson('/api/v1/auth/login', ['email' => 'google@example.com', 'password' => 'secret123'])->assertOk();
        $this->assertSame(1, User::query()->count());
    }

    public function test_rejects_invalid_tokens(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken(['aud' => 'other.apps.googleusercontent.com'])])
            ->assertUnprocessable()->assertJsonValidationErrors('id_token');

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken(['iss' => 'https://evil.example.com'])])
            ->assertUnprocessable()->assertJsonValidationErrors('id_token');

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken(['exp' => time() - 3600])])
            ->assertUnprocessable()->assertJsonValidationErrors('id_token');

        $this->postJson('/api/v1/auth/google', ['id_token' => 'not-a-jwt'])
            ->assertUnprocessable()->assertJsonValidationErrors('id_token');

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken(['email_verified' => false])])
            ->assertUnprocessable()->assertJsonValidationErrors('id_token');

        $this->assertSame(0, User::query()->count());
    }

    public function test_google_account_cannot_log_in_with_password(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken()])->assertCreated();

        $this->postJson('/api/v1/auth/login', ['email' => 'google@example.com', 'password' => 'anything'])
            ->assertUnprocessable();
    }

    public function test_banned_user_cannot_sign_in_with_google(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken()])->assertCreated();
        User::query()->update(['status' => 'banned']);

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken()])->assertForbidden();
    }
}
