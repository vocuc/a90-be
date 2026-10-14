<?php

namespace App\Http\Controllers\Api\V1\TikTok;

use App\Http\Controllers\Controller;
use App\Services\TikTok\TikTokClient;
use App\Services\TikTok\TikTokWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook TikTok (Developer Portal -> Webhooks): kết quả đăng bài và gỡ quyền ứng dụng.
 * Xác thực bằng header TikTok-Signature (HMAC-SHA256 với client_secret).
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, TikTokClient $client, TikTokWebhookService $webhooks): JsonResponse
    {
        $body = $request->getContent();
        if (! $client->verifyWebhookSignature($request->header('TikTok-Signature'), $body)) {
            return response()->json(['message' => 'Chữ ký không hợp lệ.', 'code' => 'INVALID_SIGNATURE'], 401);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Payload không hợp lệ.'], 400);
        }
        if (($payload['client_key'] ?? null) !== config('services.tiktok.client_key')) {
            return response()->json(['message' => 'ok']);
        }

        // Không tìm thấy bài của publish_id: trả 404 để TikTok gửi lại sau
        return $webhooks->handle($payload)
            ? response()->json(['message' => 'ok'])
            : response()->json(['message' => 'Không tìm thấy bài đăng.'], 404);
    }
}
