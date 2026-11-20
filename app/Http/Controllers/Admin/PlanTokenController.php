<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureChannelPlanToken;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Token cho lệnh /lap-ke-hoach (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.11): token Sanctum của admin
 * đang đăng nhập, ability "channel-plan" (đọc / ghi kênh mọi shop qua /api/v1/channel-plan). Chỉ hiện token 1 lần lúc tạo;
 * dán vào .env của skill (A51_PLAN_TOKEN).
 */
class PlanTokenController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $tokens = $request->user()->tokens()
            ->where('abilities', 'like', '%"'.EnsureChannelPlanToken::ABILITY.'"%')
            ->latest('id')->get();

        return view('admin.plan-tokens.index', ['tokens' => $tokens, 'plain' => session('plain_token')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']], ['name.required' => 'Đặt tên gợi nhớ cho token.']);
        $token = $request->user()->createToken('plan:'.trim($data['name']), [EnsureChannelPlanToken::ABILITY]);
        $this->audit->log('admin.plan_token.create', null, ['token_id' => $token->accessToken->id]);

        return redirect()->route('admin.plan-tokens.index')
            ->with('success', 'Đã tạo token. Chép ngay, token chỉ hiện 1 lần.')
            ->with('plain_token', $token->plainTextToken);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->where('abilities', 'like', '%"'.EnsureChannelPlanToken::ABILITY.'"%')->delete();
        $this->audit->log('admin.plan_token.revoke', null, ['token_id' => $token]);

        return redirect()->route('admin.plan-tokens.index')->with('success', 'Đã thu hồi token.');
    }
}
