<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Http\Controllers\Controller;
use App\Services\TikTok\TikTokConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Người dùng tự kết nối TikTok để đăng qua API (note/dang-tiktok-qua-api.txt ở repo frontend).
 * Luồng: authorize -> TikTok -> trang frontend /mxh/tiktok/author?code=&state= -> callback -> quay về trang cũ.
 */
class TikTokController extends Controller
{
    public function __construct(private TikTokConnectionService $connections) {}

    /** { redirect: "/posts/123" } -> { url } để frontend chuyển window.location sang TikTok. */
    public function authorizeUrl(Request $request): JsonResponse
    {
        $data = $request->validate(['redirect' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => ['url' => $this->connections->authorizeUrl($request->user(), $data['redirect'] ?? null)]]);
    }

    /**
     * Trang /mxh/tiktok/author gửi lên tham số TikTok trả về.
     * -> { status: connected | error, reason, redirect } (luôn 200, frontend hiện thông báo theo reason rồi về redirect)
     */
    public function callback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:2000'],
            'state' => ['nullable', 'string', 'max:200'],
            'error' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->connections->callback($request->user(), $data)]);
    }

    /** Huỷ kết nối một tài khoản TikTok (id ở GET /social/accounts). */
    public function destroy(Request $request, int $account): Response
    {
        $this->connections->disconnect($request->user(), $account);

        return response()->noContent();
    }
}
