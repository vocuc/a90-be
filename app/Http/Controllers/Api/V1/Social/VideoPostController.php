<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\GenerationOutput;
use App\Models\Hashtag;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\VideoTemplate;
use App\Services\MediaStorage;
use App\Services\ShopImageLibrary;
use App\Services\Social\SocialContentJobService;
use App\Services\Social\VideoPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Bài đăng video có lịch của chủ shop (note/lap-lich-worker.txt ở repo frontend), cần hồ sơ người bán.
 * Danh sách / xem / xoá bài dùng chung API bài đăng (/social/posts).
 */
class VideoPostController extends Controller
{
    public function __construct(private VideoPostService $videos) {}

    /**
     * "Tạo video": worker_id, count, first_at (giờ đăng bài đầu), gap_min / gap_max (phút giữa 2 bài liên tiếp).
     * 201 { data: { created, requested, stopped: null|insufficient_credit|no_template|not_enough_images, post_ids } }.
     * Không tạo được bài nào: 422 VIDEO_NO_TEMPLATE / VIDEO_NOT_ENOUGH_IMAGES, 402 INSUFFICIENT_CREDIT.
     */
    public function auto(Request $request, SocialContentJobService $contentJobs): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'integer'],
            'count' => ['required', 'integer', 'min:1', 'max:'.VideoPostService::MAX_COUNT],
            'first_at' => ['required', 'date'],
            'gap_min' => ['required', 'integer', 'min:0', 'max:'.VideoPostService::MAX_GAP_MINUTES],
            'gap_max' => ['required', 'integer', 'gte:gap_min', 'max:'.VideoPostService::MAX_GAP_MINUTES],
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9-]+$/'],
        ], [
            'count.max' => 'Mỗi lần tạo tối đa '.VideoPostService::MAX_COUNT.' bài.',
            'gap_max.gte' => 'Khoảng cách tối đa phải lớn hơn hoặc bằng tối thiểu.',
            'gap_min.max' => 'Khoảng cách tối đa '.VideoPostService::MAX_GAP_MINUTES.' phút.',
            'gap_max.max' => 'Khoảng cách tối đa '.VideoPostService::MAX_GAP_MINUTES.' phút.',
        ]);

        $user = $request->user();
        $worker = $this->videos->findWorker($user, (int) $data['worker_id']);
        $result = $this->videos->autoCreate(
            $user, $worker, (int) $data['count'], Carbon::parse($data['first_at'])->setTimezone(config('app.timezone')),
            (int) $data['gap_min'], (int) $data['gap_max'], $data['idempotency_key'],
        );

        if ($result['created'] === 0) {
            throw match ($result['stopped']) {
                VideoPostService::STOP_NO_TEMPLATE => new BusinessException('VIDEO_NO_TEMPLATE', 'Chưa có video template nào có số ảnh. Hãy thêm template hoặc điền số ảnh cho template.', 422),
                VideoPostService::STOP_NOT_ENOUGH_IMAGES => new BusinessException('VIDEO_NOT_ENOUGH_IMAGES', 'Không sản phẩm nào có đủ ảnh cho các template. Hãy tạo thêm ảnh cho sản phẩm.', 422),
                default => BusinessException::insufficientCredit($contentJobs->price(), (int) ($user->creditWallet?->balance ?? 0)),
            };
        }

        return response()->json(['data' => $result], 201);
    }

    /** Sửa bài video (popup "Sửa"): mọi thứ, khi worker chưa nhận hoặc bài lỗi. */
    public function update(Request $request, int $post): SocialPostResource
    {
        $post = $this->findVideo($request, $post);
        $data = $request->validate([
            'worker_id' => ['required', 'integer'],
            'video_template_id' => ['required', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'images' => ['required', 'array', 'min:1', 'max:'.SocialPost::MAX_IMAGES],
            'images.*.generation_id' => ['required', 'integer'],
            'images.*.output_id' => ['required', 'integer', 'distinct'],
            'scheduled_at' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:'.SocialPost::TITLE_MAX],
            'content' => ['nullable', 'string', 'max:'.SocialPost::CONTENT_MAX],
            'hashtags' => ['nullable', 'array', 'max:'.SocialPost::MAX_HASHTAGS],
            'hashtags.*' => ['string', 'max:50', 'regex:'.Hashtag::PATTERN],
        ], [
            'images.required' => 'Chọn ảnh cho bài.',
            'images.*.output_id.distinct' => 'Ảnh bị chọn trùng.',
            'hashtags.max' => 'Tối đa '.SocialPost::MAX_HASHTAGS.' hashtag.',
        ]);
        $data['scheduled_at'] = Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone'));

        return $this->resource($this->videos->update($request->user(), $post, $data));
    }

    /** "Đăng lại" (bài lỗi) / "Tạo lại" (worker im lặng): bài về chờ tới giờ. */
    public function retry(Request $request, int $post): SocialPostResource
    {
        return $this->resource($this->videos->retry($request->user(), $this->findVideo($request, $post)));
    }

    /**
     * Ảnh chọn cho bài trong popup Sửa: product_id -> ảnh của sản phẩm (trong các lô của shop); không gửi -> mọi ảnh đã
     * tạo. Mới nhất trước. { data: [{ generation_id, output_id, thumb_url, image_url }], meta }.
     */
    public function images(Request $request, ShopImageLibrary $library, MediaStorage $media): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $userId = $request->user()->id;
        if ($productId = $data['product_id'] ?? null) {
            Product::query()->where('user_id', $userId)->findOrFail($productId);
        }

        $page = ($productId ? $library->productImages($userId, (int) $productId) : $library->allImages($userId))
            ->latest('id')
            ->paginate($data['per_page'] ?? 40, ['id', 'generation_id', 'image_path', 'thumb_path']);

        return response()->json([
            'data' => collect($page->items())->map(fn (GenerationOutput $o) => [
                'generation_id' => $o->generation_id,
                'output_id' => $o->id,
                'thumb_url' => $media->url($o->thumbPathOrOriginal()),
                'image_url' => $media->url($o->image_path),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Template dùng được cho bài video (có số ảnh), để popup Sửa chọn. */
    public function templates(Request $request): JsonResponse
    {
        return response()->json(['data' => VideoTemplate::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('image_count')
            ->orderBy('name')
            ->get(['id', 'name', 'video_url', 'image_count'])]);
    }

    private function findVideo(Request $request, int $id): SocialPost
    {
        return SocialPost::query()
            ->where('user_id', $request->user()->id)
            ->where('type', SocialPost::TYPE_VIDEO)
            ->findOrFail($id);
    }

    private function resource(SocialPost $post): SocialPostResource
    {
        return new SocialPostResource($post->fresh(SocialPostResource::RELATIONS));
    }
}
