<?php

namespace App\Http\Controllers\Api\V1\TikTok;

use App\Enums\TikTokPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TikTokPostResource;
use App\Models\Hashtag;
use App\Models\TikTokPost;
use App\Services\TikTok\TikTokPostService;
use App\Services\TikTok\TikTokPublishJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Bài đăng TikTok của người dùng, xem note/nghiep-vu-dang-bai-tiktik.txt (mục 5).
 * Bài của người khác -> 404.
 */
class PostController extends Controller
{
    public function __construct(private TikTokPostService $posts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(TikTokPostStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $posts = TikTokPost::query()
            ->where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(TikTokPostResource::RELATIONS)
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return TikTokPostResource::collection($posts);
    }

    public function show(Request $request, int $post): TikTokPostResource
    {
        return $this->resource($this->findPost($request, $post));
    }

    /** Tạo bài từ ảnh của một lô (image_batch_id) hoặc ảnh đã có trong lịch sử tạo ảnh. Không tính phí. */
    public function storeFromImages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.TikTokPost::MAX_IMAGES],
            'images.*.generation_id' => ['required', 'integer'],
            'images.*.output_id' => ['required', 'integer', 'distinct'],
            'image_batch_id' => ['nullable', 'integer', Rule::exists('image_batches', 'id')->where('user_id', $request->user()->id)],
            'idempotency_key' => ['required', 'string', 'max:80'],
            ...self::contentRules(),
        ], [
            'images.max' => 'Tối đa '.TikTokPost::MAX_IMAGES.' ảnh mỗi bài.',
            'images.*.output_id.distinct' => 'Ảnh bị chọn trùng.',
            'image_batch_id.exists' => 'Lô ảnh không tồn tại.',
            ...self::contentMessages(),
        ]);

        $post = $this->posts->createFromImages($request->user(), array_values($data['images']), $data['idempotency_key'], $data, $data['image_batch_id'] ?? null);

        return $this->resource($post)->response()->setStatusCode(201);
    }

    /** "Đăng TikTok": body là toàn bộ chỉnh sửa trên trang; tạo job cho worker lưu nháp, bài sang queued. */
    public function publish(Request $request, int $post, TikTokPublishJobService $publisher): TikTokPostResource
    {
        $post = $this->findPost($request, $post);
        $data = $request->validate([
            ...self::contentRules(),
            'images' => ['required', 'array', 'min:1', 'max:'.TikTokPost::MAX_IMAGES],
            'images.*' => ['array'],
            'images.*.image_id' => ['nullable', 'integer'],
            'images.*.generation_id' => ['required_without:images.*.image_id', 'nullable', 'integer'],
            'images.*.output_id' => ['required_without:images.*.image_id', 'nullable', 'integer'],
        ], [
            'images.max' => 'Tối đa '.TikTokPost::MAX_IMAGES.' ảnh mỗi bài.',
            ...self::contentMessages(),
        ]);
        $data['images'] = array_values($data['images']);

        return $this->resource($publisher->publish($request->user(), $post, $data));
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
            'title' => ['nullable', 'string', 'max:'.TikTokPost::TITLE_MAX],
            'content' => ['nullable', 'string', 'max:'.TikTokPost::CONTENT_MAX],
            'hashtags' => ['nullable', 'array', 'max:'.TikTokPost::MAX_HASHTAGS],
            'hashtags.*' => ['string', 'max:50', 'regex:'.Hashtag::PATTERN],
        ];
    }

    private static function contentMessages(): array
    {
        return ['hashtags.max' => 'Tối đa '.TikTokPost::MAX_HASHTAGS.' hashtag.'];
    }

    private function findPost(Request $request, int $id): TikTokPost
    {
        return TikTokPost::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function resource(TikTokPost $post): TikTokPostResource
    {
        return new TikTokPostResource($post->fresh(TikTokPostResource::RELATIONS));
    }
}
