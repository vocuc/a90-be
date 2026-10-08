<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Enums\ApiKeyStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use App\Models\AiProvider;
use App\Models\CreatorApiKey;
use App\Services\AI\Exceptions\InvalidApiKeyException;
use App\Services\AI\Exceptions\ProviderException;
use App\Services\AI\ModelService;
use App\Services\AuditLogger;
use App\Services\CreativeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Creator tự quản lý API key của mình. Key được mã hóa trong DB và không bao giờ trả về.
 */
class ApiKeyController extends Controller
{
    public function __construct(
        private ModelService $models,
        private CreativeService $creatives,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ApiKeyResource::collection($request->user()->apiKeys()->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'integer', Rule::exists('ai_providers', 'id')->where('is_active', true)],
            'label' => ['required', 'string', 'max:100'],
            'api_key' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $provider = AiProvider::query()->findOrFail($data['provider_id']);
        $this->verify($provider, $data['api_key']);

        $key = $request->user()->apiKeys()->create([
            'provider_id' => $provider->id,
            'label' => $data['label'],
            'api_key' => $data['api_key'],
            'key_hint' => substr($data['api_key'], -4),
            'status' => ApiKeyStatus::Active,
        ]);
        $this->audit->log('api_key.create', $key, ['provider' => $provider->code]);

        return (new ApiKeyResource($key))->response()->setStatusCode(201);
    }

    public function update(Request $request, CreatorApiKey $apiKey): ApiKeyResource
    {
        $this->authorizeOwner($request, $apiKey);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in([ApiKeyStatus::Active->value, ApiKeyStatus::Disabled->value])],
        ]);

        // Bật lại key thì kiểm tra lại với Provider.
        if (($data['status'] ?? null) === ApiKeyStatus::Active->value && $apiKey->status !== ApiKeyStatus::Active) {
            $this->verify($apiKey->provider()->firstOrFail(), $apiKey->api_key);
            $data += ['cooldown_until' => null, 'last_error' => null];
        }

        $apiKey->update($data);

        if ($apiKey->status !== ApiKeyStatus::Active) {
            $this->creatives->pauseIfNoActiveKey($apiKey->user_id, $apiKey->provider_id);
        }

        return new ApiKeyResource($apiKey);
    }

    public function destroy(Request $request, CreatorApiKey $apiKey): JsonResponse
    {
        $this->authorizeOwner($request, $apiKey);

        $apiKey->delete();
        $this->audit->log('api_key.delete', $apiKey);
        $paused = $this->creatives->pauseIfNoActiveKey($apiKey->user_id, $apiKey->provider_id);

        return response()->json(['message' => 'Đã xóa API key.', 'paused_creatives' => $paused]);
    }

    private function verify(AiProvider $provider, string $apiKey): void
    {
        try {
            $this->models->provider($provider)->verifyKey($apiKey);
        } catch (InvalidApiKeyException|ProviderException $e) {
            $invalid = $e instanceof InvalidApiKeyException;

            throw new BusinessException(
                $invalid ? 'API_KEY_INVALID' : 'API_KEY_VERIFY_FAILED',
                $invalid ? 'API key không hợp lệ.' : 'Không kiểm tra được API key, vui lòng thử lại.',
                422,
            );
        }
    }

    private function authorizeOwner(Request $request, CreatorApiKey $apiKey): void
    {
        abort_unless($apiKey->user_id === $request->user()->id, 404);
    }
}
