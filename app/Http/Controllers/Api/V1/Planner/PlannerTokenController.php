<?php

namespace App\Http\Controllers\Api\V1\Planner;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nút "Tạo token kế hoạch" ở trang tài khoản: token dài hạn cho skill kế hoạch nội dung, chỉ gọi được /planner/*
 * (ability planner, xem EnsureFullAccessToken). Mỗi tài khoản 1 token: tạo lại thì token cũ hết hiệu lực.
 */
class PlannerTokenController extends Controller
{
    public const NAME = 'planner';

    public const ABILITY = 'planner';

    public function __construct(private AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $token = $request->user()->tokens()->where('name', self::NAME)->latest('id')->first();

        return response()->json(['data' => $token
            ? ['exists' => true, 'created_at' => $token->created_at, 'last_used_at' => $token->last_used_at]
            : ['exists' => false, 'created_at' => null, 'last_used_at' => null]]);
    }

    /** Token chỉ trả về đúng 1 lần ở đây. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->tokens()->where('name', self::NAME)->delete();
        $token = $user->createToken(self::NAME, [self::ABILITY]);
        $this->audit->log('planner_token.create', $user, [], $user->id);

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'created_at' => $token->accessToken->created_at,
        ]], 201);
    }
}
