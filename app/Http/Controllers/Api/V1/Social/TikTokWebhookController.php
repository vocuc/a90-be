<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Http\Controllers\Controller;
use App\Services\TikTok\TikTokApiPublisher;
use App\Services\TikTok\TikTokClient;
use App\Services\TikTok\TikTokConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook TikTok (Developer Portal -> Webhooks): kết quả gửi bài (post.publish.*) và gỡ quyền ứng dụng
 * (authorization.removed). Xác thực bằng header TikTok-Signature (HMAC-SHA256 với client_secret).
 * Payload: { client_key, event, create_time, user_openid, content: "<chuỗi JSON>" }.
 */
class TikTokWebhookController extends Controller
{
    public function __invoke(Request $request, TikTokClient $client, TikTokApiPublisher $publisher, TikTokConnectionService $connections): JsonResponse
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

        $event = (string) ($payload['event'] ?? '');
        $content = is_string($payload['content'] ?? null) ? (json_decode($payload['content'], true) ?: []) : (array) ($payload['content'] ?? []);

        if ($event === 'authorization.removed') {
            if (! empty($payload['user_openid'])) {
                $connections->authorizationRemoved((string) $payload['user_openid']);
            }

            return response()->json(['message' => 'ok']);
        }

        // Không tìm thấy job của publish_id: trả 404 để TikTok gửi lại sau
        if (str_starts_with($event, 'post.publish.') && ! $publisher->handlePublishEvent($event, $content)) {
            return response()->json(['message' => 'Không tìm thấy bài đăng.'], 404);
        }

        return response()->json(['message' => 'ok']);
    }
}
