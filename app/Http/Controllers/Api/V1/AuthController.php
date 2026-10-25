<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\CreditWallet;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GoogleIdentity;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create($data);
            CreditWallet::query()->create(['user_id' => $user->id]);

            return $user;
        });

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }
        if (! $user->isActive()) {
            throw new BusinessException('ACCOUNT_BANNED', 'Tài khoản đã bị khóa.', 403);
        }

        $this->audit->log('auth.login', $user, [], $user->id);

        return $this->tokenResponse($user);
    }

    /**
     * Đăng nhập / đăng ký bằng Google. Frontend gửi id_token (credential) lấy từ nút Google Identity Services.
     * Lần đầu -> tạo tài khoản (201); email đã đăng ký bằng mật khẩu -> gắn vào tài khoản đó.
     */
    public function google(Request $request, GoogleIdentity $google): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $identity = $google->verify($data['id_token']);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException) {
            throw ValidationException::withMessages(['id_token' => 'Không xác thực được tài khoản Google, vui lòng thử lại.']);
        }

        $account = SocialAccount::query()
            ->where('provider', SocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $identity['sub'])
            ->first();
        $created = false;

        if ($account) {
            $user = $account->user;
        } else {
            // Chỉ tin email Google đã xác minh, tránh chiếm email của người khác
            if (! $identity['email'] || ! $identity['email_verified']) {
                throw ValidationException::withMessages(['id_token' => 'Email của tài khoản Google chưa được xác minh.']);
            }

            [$user, $created] = DB::transaction(function () use ($identity) {
                $user = User::query()->where('email', $identity['email'])->first();
                $created = ! $user;

                if ($created) {
                    $user = User::query()->create([
                        'name' => mb_substr(trim((string) $identity['name']), 0, 100) ?: Str::before($identity['email'], '@'),
                        'email' => $identity['email'],
                    ]);
                    $user->forceFill(['email_verified_at' => now()])->save();
                    CreditWallet::query()->create(['user_id' => $user->id]);
                }

                $user->socialAccounts()->create([
                    'provider' => SocialAccount::PROVIDER_GOOGLE,
                    'provider_user_id' => $identity['sub'],
                    'email' => $identity['email'],
                ]);

                return [$user, $created];
            });
        }

        if (! $user->isActive()) {
            throw new BusinessException('ACCOUNT_BANNED', 'Tài khoản đã bị khóa.', 403);
        }

        $this->audit->log('auth.login', $user, ['provider' => SocialAccount::PROVIDER_GOOGLE], $user->id);

        return $this->tokenResponse($user, $created ? 201 : 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['creditWallet', 'creatorProfile', 'staffMembership.owner.creatorProfile']));
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $expiresAt = now()->addDays(config('ai90.auth.token_ttl_days'));
        $token = $user->createToken('api', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
                'user' => new UserResource($user->load(['creditWallet', 'creatorProfile'])),
            ],
        ], $status);
    }
}
