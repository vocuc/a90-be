<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\Hashtag;
use App\Models\SocialPost;
use App\Services\Social\PlannerService;
use App\Services\Social\SocialContentJobService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Bài đăng của người dùng (ảnh / video, worker đăng lên TikTok / Facebook / Instagram), xem note/gop-luong-dang-bai.txt
 * ở repo frontend. Bài của người khác -> 404.
 */
class PostController extends Controller
{
    private const CROP_MAX_KB = 20480;

    public function __construct(private SocialPostService $posts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(SocialPostStatus::class)],
            'type' => ['nullable', Rule::in([SocialPost::TYPE_PHOTO, SocialPost::TYPE_VIDEO])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            // Lọc theo worker đã giao bài (trang /posts ở repo frontend)
            'worker_id' => ['nullable', 'integer'],
            // Lọc theo nguồn tạo bài, vd plan (bài do skill kế hoạch nội dung tạo)
            'source' => ['nullable', Rule::in([SocialPost::SOURCE_BATCH, SocialPost::SOURCE_EXISTING, SocialPost::SOURCE_VIDEO, SocialPost::SOURCE_PLAN])],
            // Lọc theo ngày đăng (scheduled_at), ngày theo giờ Việt Nam (ai90.social.planner_timezone)
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $posts = SocialPost::query()
            ->where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($data['worker_id'] ?? null, fn ($q, $workerId) => $q->where('worker_id', $workerId))
            ->when($data['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($data['date_from'] ?? null, fn ($q, $d) => $q->where('scheduled_at', '>=', Carbon::createFromFormat('Y-m-d', $d, config('ai90.social.planner_timezone'))->startOfDay()->utc()))
            ->when($data['date_to'] ?? null, fn ($q, $d) => $q->where('scheduled_at', '<=', Carbon::createFromFormat('Y-m-d', $d, config('ai90.social.planner_timezone'))->endOfDay()->utc()))
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
     * "Đăng bài" (note/gop-luong-dang-bai.txt ở repo frontend): body là toàn bộ chỉnh sửa trên trang + worker (chọn ở thanh
     * tiêu đề), loại bài (photo | video: thêm video_template_id, đúng số ảnh template cần), nền tảng (một hoặc nhiều),
     * giờ đăng (không gửi: bây giờ). Bài sang queued, worker nhận khi tới giờ. Bài chờ gửi hoặc đang chờ tới giờ.
     */
    public function publish(Request $request, int $post, SocialPublishJobService $publisher): SocialPostResource
    {
        $post = $this->findPost($request, $post);
        $data = $request->validate([
            ...self::contentRules(),
            'worker_id' => ['required', 'integer'],
            'type' => ['nullable', Rule::in([SocialPost::TYPE_PHOTO, SocialPost::TYPE_VIDEO])],
            'video_template_id' => ['required_if:type,video', 'nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
            'images' => ['required', 'array', 'min:1', 'max:'.SocialPost::MAX_IMAGES],
            'images.*' => ['array'],
            'images.*.image_id' => ['nullable', 'integer'],
            'images.*.generation_id' => ['required_without:images.*.image_id', 'nullable', 'integer'],
            'images.*.output_id' => ['required_without:images.*.image_id', 'nullable', 'integer'],
            ...self::platformRules(),
        ], [
            'worker_id.required' => 'Chọn worker.',
            'video_template_id.required_if' => 'Chọn video template.',
            'images.max' => 'Tối đa '.SocialPost::MAX_IMAGES.' ảnh mỗi bài.',
            ...self::platformMessages(),
            ...self::contentMessages(),
        ]);
        $data['images'] = array_values($data['images']);
        $data['type'] ??= SocialPost::TYPE_PHOTO;
        $data['scheduled_at'] = isset($data['scheduled_at'])
            ? Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone'))
            : now();

        return $this->resource($publisher->publish($request->user(), $post, $data));
    }

    /** "Đăng lại" (bài lỗi) / "Tạo lại" (worker nhận rồi im lặng, có thể đăng trùng): bài về chờ tới giờ. */
    /** "Làm lại" bài kế hoạch đang chờ duyệt: tạo lại ảnh theo đúng kịch bản, miễn phí (PlannerService::redo). */
    public function redo(Request $request, int $post, PlannerService $planner): SocialPostResource
    {
        return $this->resource($planner->redo($request->user(), $this->findPost($request, $post)));
    }

    /** "Duyệt" bài kế hoạch: lưu nội dung đang sửa (nếu gửi) rồi sang chờ gửi (PlannerService::approve). */
    public function approve(Request $request, int $post, PlannerService $planner): SocialPostResource
    {
        $post = $this->findPost($request, $post);
        $data = $request->validate(self::contentRules(), self::contentMessages());

        return $this->resource($planner->approve($request->user(), $post, $data));
    }

    /** "Bỏ duyệt": bài kế hoạch đã duyệt, chưa bấm đăng -> về chờ duyệt. */
    public function unapprove(Request $request, int $post, PlannerService $planner): SocialPostResource
    {
        return $this->resource($planner->unapprove($request->user(), $this->findPost($request, $post)));
    }

    /** "Tạo lại ảnh này" của bài kế hoạch đang chờ duyệt: chỉ tạo lại 1 ảnh theo câu lệnh của nó, miễn phí (PlannerService::redoImage). */
    public function redoImage(Request $request, int $post, int $image, PlannerService $planner): SocialPostResource
    {
        return $this->resource($planner->redoImage($request->user(), $this->findPost($request, $post), $image));
    }

    public function retry(Request $request, int $post, SocialPublishJobService $publisher): SocialPostResource
    {
        return $this->resource($publisher->retry($request->user(), $this->findPost($request, $post)));
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

    /** "Cắt ảnh": multipart image = ảnh đã cắt trên trình duyệt, ghi đè ảnh gốc. Chỉ khi bài đang chờ gửi. */
    public function cropImage(Request $request, int $post, int $image): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::CROP_MAX_KB],
        ], [
            'image.max' => 'Ảnh đã cắt tối đa '.(self::CROP_MAX_KB / 1024).'MB.',
        ]);

        $cropped = $this->posts->cropImage($request->user(), $this->findPost($request, $post), $image, $request->file('image'));

        return response()->json(['data' => SocialPostResource::image($cropped)]);
    }

    /** "Hủy bỏ" bài đang chuẩn bị: dừng tạo ảnh / viết nội dung và xoá bài. 409 POST_NOT_CANCELLABLE khi đã qua bước chuẩn bị. */
    public function cancel(Request $request, int $post): Response
    {
        $this->posts->cancel($request->user(), $this->findPost($request, $post));

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

    /** Nền tảng đăng (bắt buộc, một hoặc nhiều). Dùng chung với bài video. */
    public static function platformRules(): array
    {
        return [
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', 'distinct', Rule::enum(SocialPlatform::class)],
        ];
    }

    public static function platformMessages(): array
    {
        return [
            'platforms.required' => 'Chọn ít nhất một nền tảng để đăng.',
            'platforms.min' => 'Chọn ít nhất một nền tảng để đăng.',
            'platforms.*.enum' => 'Nền tảng không hợp lệ.',
        ];
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
