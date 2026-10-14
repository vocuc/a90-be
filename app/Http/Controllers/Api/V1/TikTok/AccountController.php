<?php

namespace App\Http\Controllers\Api\V1\TikTok;

use App\Http\Controllers\Controller;
use App\Services\TikTok\TikTokAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ủy quyền tài khoản TikTok, xem note/uy-quyen-tiktok.txt.
 */
class AccountController extends Controller
{
    public function __construct(private TikTokAccountService $accounts) {}

    /** Trạng thái ủy quyền + creator_info lấy mới mỗi lần gọi. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->accounts->status($request->user())]);
    }

    public function authorizeUrl(Request $request): JsonResponse
    {
        $data = $request->validate(['redirect' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => [
            'url' => $this->accounts->authorizeUrl($request->user(), $data['redirect'] ?? null),
        ]]);
    }

    /** TikTok gọi về (không có Bearer), người dùng xác định qua state. Luôn redirect 302 về frontend. */
    public function callback(Request $request): RedirectResponse
    {
        return redirect()->away($this->accounts->handleCallback([
            'code' => $request->query('code'),
            'state' => $request->query('state'),
            'error' => $request->query('error'),
        ]));
    }

    public function destroy(Request $request): Response
    {
        $this->accounts->disconnect($request->user());

        return response()->noContent();
    }
}
