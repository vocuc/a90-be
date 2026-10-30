<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Models\Hashtag;
use App\Models\ShopAiSetting;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\SocialContentJobService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Trang "AI viết nội dung" của shop (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend), chỉ chủ shop sửa:
 * - hashtags: kho hashtag, AI chọn tối đa ai90.social.ai_max_hashtags hashtag trong kho (rỗng: bài không có hashtag).
 * - samples: bài viết mẫu [{ title, content }], AI học giọng văn / cách trình bày, không chép nguyên.
 * - title_max, content_max: số ký tự tối đa của tiêu đề / nội dung AI viết.
 * Nhân viên dùng cài đặt của chủ shop. Kho không tự lấy hashtag sản phẩm: product_hashtags trả kèm cho nút
 * "Lấy hashtag từ sản phẩm".
 */
class AiContentSettingController extends Controller
{
    public const MAX_HASHTAGS = 100;

    public function show(Request $request): JsonResponse
    {
        return $this->response($request->user());
    }

    /** Gửi đủ cả trang: hashtags, samples theo thứ tự (rỗng = xoá hết), title_max, content_max. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hashtags' => ['nullable', 'array', 'max:'.self::MAX_HASHTAGS],
            'hashtags.*' => ['required', 'string', 'max:50', 'regex:'.Hashtag::PATTERN, function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && Hashtag::slugFor($value) === '') {
                    $fail('Hashtag phải chứa chữ cái hoặc chữ số.');
                }
            }],
            'samples' => ['nullable', 'array', 'max:'.ShopAiSetting::MAX_SAMPLES],
            'samples.*.title' => ['nullable', 'string', 'max:'.SocialPost::TITLE_MAX],
            'samples.*.content' => ['required', 'string', 'max:'.SocialPost::CONTENT_MAX],
            'title_max' => ['required', 'integer', 'min:'.ShopAiSetting::TITLE_MAX_MIN, 'max:'.SocialPost::TITLE_MAX],
            'content_max' => ['required', 'integer', 'min:'.ShopAiSetting::CONTENT_MAX_MIN, 'max:'.SocialContentJobService::AI_CONTENT_MAX],
        ], [
            'hashtags.max' => 'Tối đa '.self::MAX_HASHTAGS.' hashtag.',
            'hashtags.*.regex' => 'Hashtag chỉ gồm chữ, số và dấu gạch dưới.',
            'samples.max' => 'Tối đa '.ShopAiSetting::MAX_SAMPLES.' bài viết mẫu.',
            'samples.*.content.required' => 'Bài viết mẫu cần có nội dung.',
        ]);

        $user = $request->user();
        DB::transaction(function () use ($user, $data) {
            $user->shopHashtags()->sync(Hashtag::syncPivot($data['hashtags'] ?? []));
            ShopAiSetting::query()->updateOrCreate(['owner_id' => $user->id], [
                'title_max' => $data['title_max'],
                'content_max' => $data['content_max'],
                'samples' => collect($data['samples'] ?? [])
                    ->map(fn ($s) => ['title' => trim((string) ($s['title'] ?? '')), 'content' => trim($s['content'])])
                    ->values()
                    ->all(),
            ]);
        });

        return $this->response($user);
    }

    private function response(User $user): JsonResponse
    {
        $productHashtags = Hashtag::query()
            ->whereHas('products', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('name')
            ->pluck('name');

        return response()->json(['data' => [
            ...ShopAiSetting::forOwner($user->id),
            'hashtags' => $user->shopHashtags()->pluck('name')->values(),
            // Hashtag các sản phẩm của shop, cho nút "Lấy hashtag từ sản phẩm"
            'product_hashtags' => $productHashtags->values(),
            'limits' => [
                'hashtags' => self::MAX_HASHTAGS,
                'ai_hashtags' => config('ai90.social.ai_max_hashtags'),
                'samples' => ShopAiSetting::MAX_SAMPLES,
                'sample_title' => SocialPost::TITLE_MAX,
                'sample_content' => SocialPost::CONTENT_MAX,
                'title_max' => ['min' => ShopAiSetting::TITLE_MAX_MIN, 'max' => SocialPost::TITLE_MAX],
                'content_max' => ['min' => ShopAiSetting::CONTENT_MAX_MIN, 'max' => SocialContentJobService::AI_CONTENT_MAX],
            ],
        ]]);
    }
}
