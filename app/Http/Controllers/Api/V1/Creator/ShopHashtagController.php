<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Models\Hashtag;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kho hashtag của shop (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend): AI viết nội dung bài đăng chỉ chọn tối đa
 * ai90.social.ai_max_hashtags hashtag trong kho này; kho rỗng thì bài không có hashtag. Nhân viên dùng kho của chủ shop.
 * Kho không tự lấy hashtag sản phẩm: product_hashtags trả kèm để chủ shop bấm "Lấy hashtag từ sản phẩm".
 */
class ShopHashtagController extends Controller
{
    public const MAX_HASHTAGS = 100;

    public function show(Request $request): JsonResponse
    {
        return $this->response($request->user());
    }

    /** Gửi cả danh sách theo thứ tự (không gửi / rỗng = xoá hết). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hashtags' => ['nullable', 'array', 'max:'.self::MAX_HASHTAGS],
            'hashtags.*' => ['required', 'string', 'max:50', 'regex:'.Hashtag::PATTERN, function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && Hashtag::slugFor($value) === '') {
                    $fail('Hashtag phải chứa chữ cái hoặc chữ số.');
                }
            }],
        ], [
            'hashtags.max' => 'Tối đa '.self::MAX_HASHTAGS.' hashtag.',
            'hashtags.*.regex' => 'Hashtag chỉ gồm chữ, số và dấu gạch dưới.',
        ]);

        $user = $request->user();
        $user->shopHashtags()->sync(Hashtag::syncPivot($data['hashtags'] ?? []));

        return $this->response($user);
    }

    private function response(User $user): JsonResponse
    {
        $productHashtags = Hashtag::query()
            ->whereHas('products', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('name')
            ->pluck('name');

        return response()->json(['data' => [
            'hashtags' => $user->shopHashtags()->pluck('name')->values(),
            // Hashtag các sản phẩm của shop, cho nút "Lấy hashtag từ sản phẩm"
            'product_hashtags' => $productHashtags->values(),
            'max' => self::MAX_HASHTAGS,
        ]]);
    }
}
