<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\Hashtag;
use App\Models\PublishAccount;
use App\Models\SocialPost;
use App\Services\Social\SocialContentJobService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishJobService;
use App\Services\TikTok\TikTokConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Bài đăng của người dùng (đăng lên TikTok / Facebook / Instagram), xem note/nghiep-vu-dang-bai-tiktik.txt (mục 5)
 * và note/dang-bai-da-nen-tang.txt. Bài của người khác -> 404.
 */
class PostController extends Controller
{
    public function __construct(private SocialPostService $posts) {}

    /**
     * Tài khoản đăng bài của người dùng (admin gắn worker, hoặc TikTok tự kết nối): trang đăng bài chọn sẵn các nền tảng này.
     * driver api: kèm connection { status: active | expired | revoked | scope_missing, avatar_url, connected_at }.
     * TikTok tự kết nối có thể nhiều dòng; id dùng cho tiktok_account_ids khi đăng và để huỷ kết nối.
     */
    public function accounts(Request $request, SocialPublishJobService $publisher, TikTokConnectionService $tiktok): JsonResponse
    {
        return response()->json(['data' => $publisher->accounts($request->user())->map(fn (PublishAccount $account) => [
            'id' => $account->id,
            'platform' => $account->platform->value,
            'label' => $account->platform->label(),
            'name' => $account->name,
            'username' => $account->username,
            'is_active' => $account->is_active,
            'driver' => $account->driver->value,
            'connection' => $account->isApi() ? $tiktok->connection($account) : null,
        ])->values()]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(SocialPostStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $posts = SocialPost::query()
            ->where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(SocialPostResource::RELATIONS)
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return SocialPostResource::collection($posts);
    }

    public function show(Request $request, int $post): SocialPostResource
    {
        return $this->resource($this->findPost($request, $post));
    }

    /**
     * Tạo bài từ ảnh của một lô (image_batch_id) hoặc ảnh đã có trong lịch sử tạo ảnh. Không tính phí, trừ khi bật
     * ai_content (AI viết nội dung, note/ai-viet-noi-dung-bai-dang.txt ở repo frontend): trừ giá social.ai_content_price.
     */
    public function storeFromImages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.SocialPost::MAX_IMAGES],
            'images.*.generation_id' => ['required', 'integer'],
            'images.*.output_id' => ['required', 'integer', 'distinct'],
            'image_batch_id' => ['nullable', 'integer', Rule::exists('image_batches', 'id')->where('user_id', $request->user()->id)],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'ai_content' => ['nullable', 'boolean'],
            ...self::contentRules(),
        ], [
            'images.max' => 'Tối đa '.SocialPost::MAX_IMAGES.' ảnh mỗi bài.',
            'images.*.output_id.distinct' => 'Ảnh bị chọn trùng.',
            'image_batch_id.exists' => 'Lô ảnh không tồn tại.',
            ...self::contentMessages(),
        ]);

        $post = $this->posts->createFromImages(
            $request->user(), array_values($data['images']), $data['idempotency_key'], $data, $data['image_batch_id'] ?? null, (bool) ($data['ai_content'] ?? false),
        );

        return $this->resource($post)->response()->setStatusCode(201);
    }

    /**
     * "Đăng bài": body là toàn bộ chỉnh sửa trên trang + các nền tảng người dùng chọn trong popup (platforms, bắt buộc,
     * một hoặc nhiều); tạo 1 job / nền tảng cho worker, bài sang queued.
     */
    public function publish(Request $request, int $post, SocialPublishJobService $publisher): SocialPostResource
    {
        $post = $this->findPost($request, $post);
        $data = $request->validate([
            ...self::contentRules(),
            'images' => ['required', 'array', 'min:1', 'max:'.SocialPost::MAX_IMAGES],
            'images.*' => ['array'],
            'images.*.image_id' => ['nullable', 'integer'],
            'images.*.generation_id' => ['required_without:images.*.image_id', 'nullable', 'integer'],
            'images.*.output_id' => ['required_without:images.*.image_id', 'nullable', 'integer'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', 'distinct', Rule::enum(SocialPlatform::class)],
            // TikTok tự kết nối: gửi vào các tài khoản này (bỏ trống khi chỉ có 1 tài khoản)
            'tiktok_account_ids' => ['nullable', 'array', 'max:'.TikTokConnectionService::maxAccounts()],
            'tiktok_account_ids.*' => ['integer', 'distinct'],
        ], [
            'platforms.required' => 'Chọn ít nhất một nền tảng để đăng.',
            'platforms.min' => 'Chọn ít nhất một nền tảng để đăng.',
            'platforms.*.enum' => 'Nền tảng không hợp lệ.',
            'images.max' => 'Tối đa '.SocialPost::MAX_IMAGES.' ảnh mỗi bài.',
            ...self::contentMessages(),
        ]);
        $data['images'] = array_values($data['images']);

        return $this->resource($publisher->publish($request->user(), $post, $data));
    }

    /**
     * "Viết lại bằng AI": trả { title, content, hashtags, credit_balance } để frontend điền vào form, KHÔNG lưu vào bài.
     * Chỉ khi bài đang chờ gửi. Trừ social.ai_content_price khi AI viết được; AI lỗi -> 503 AI_CONTENT_FAILED, không trừ.
     */
    public function aiContent(Request $request, int $post, SocialContentJobService $contentJobs): JsonResponse
    {
        $post = $this->findPost($request, $post);
        $this->posts->assertEditable($post);

        return response()->json(['data' => $contentJobs->rewrite($request->user(), $post)]);
    }

    public function destroy(Request $request, int $post): Response
    {
        $this->posts->delete($request->user(), $this->findPost($request, $post));

        return response()->noContent();
    }

    /** Tiêu đề / nội dung / hashtag: không bắt buộc. */
    private static function contentRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:'.SocialPost::TITLE_MAX],
            'content' => ['nullable', 'string', 'max:'.SocialPost::CONTENT_MAX],
            'hashtags' => ['nullable', 'array', 'max:'.SocialPost::MAX_HASHTAGS],
            'hashtags.*' => ['string', 'max:50', 'regex:'.Hashtag::PATTERN],
        ];
    }

    private static function contentMessages(): array
    {
        return ['hashtags.max' => 'Tối đa '.SocialPost::MAX_HASHTAGS.' hashtag.'];
    }

    private function findPost(Request $request, int $id): SocialPost
    {
        return SocialPost::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function resource(SocialPost $post): SocialPostResource
    {
        return new SocialPostResource($post->fresh(SocialPostResource::RELATIONS));
    }
}
