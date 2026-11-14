<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Jobs\CreateScheduledBatchPost;
use App\Models\Hashtag;
use App\Models\ImageBatch;
use App\Models\SocialPost;
use App\Services\Social\SocialContentJobService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bài đăng của người dùng (ảnh / video, worker đăng lên TikTok / Facebook / Instagram), xem note/gop-luong-dang-bai.txt
 * ở repo frontend. Bài của người khác -> 404.
 */
class PostController extends Controller
{
    /** Số lô tối đa mỗi lần "Lập lịch đăng bài". */
    public const SCHEDULE_MAX_BATCHES = 100;

    public function __construct(private SocialPostService $posts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(SocialPostStatus::class)],
            'type' => ['nullable', Rule::in([SocialPost::TYPE_PHOTO, SocialPost::TYPE_VIDEO])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            // Lọc theo worker đã giao bài (trang /posts ở repo frontend)
            'worker_id' => ['nullable', 'integer'],
            // Lọc theo nguồn tạo bài, vd lookbook (bài lookbook cũ)
            'source' => ['nullable', Rule::in([SocialPost::SOURCE_BATCH, SocialPost::SOURCE_EXISTING, SocialPost::SOURCE_VIDEO, SocialPost::SOURCE_LOOKBOOK])],
        ]);

        $posts = SocialPost::query()
            ->where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($data['worker_id'] ?? null, fn ($q, $workerId) => $q->where('worker_id', $workerId))
            ->when($data['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
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
     * ai_content (AI viết nội dung, note/ai-viet-noi-dung-bai-dang.txt ở repo frontend).
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

    /**
     * "Lập lịch đăng bài" nhiều lô (trang /batches ở repo frontend): mỗi lô thành 1 bài ảnh giao cho worker, giờ đăng dải
     * đều từ start_at (lô đầu) tới end_at (lô cuối) theo thứ tự batch_ids. Chỉ kiểm tra rồi đưa vào queue
     * (CreateScheduledBatchPost, 1 job / lô), trả 202 ngay; bài hiện dần ở trang Bài đăng.
     * Gửi lại cùng idempotency_key: không tạo bài trùng.
     */
    public function scheduleBatches(Request $request, SocialPublishJobService $publisher): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'batch_ids' => ['required', 'array', 'min:1', 'max:'.self::SCHEDULE_MAX_BATCHES],
            'batch_ids.*' => ['integer', 'distinct', Rule::exists('image_batches', 'id')->where('user_id', $user->id)],
            'worker_id' => ['required', 'integer'],
            'start_at' => ['required', 'date', 'after_or_equal:'.now()->subMinutes(5)->toIso8601String()],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
            'ai_content' => ['nullable', 'boolean'],
            'idempotency_key' => ['required', 'string', 'max:60'],
            ...self::platformRules(),
        ], [
            'batch_ids.required' => 'Chọn ít nhất một lô.',
            'batch_ids.max' => 'Tối đa '.self::SCHEDULE_MAX_BATCHES.' lô mỗi lần.',
            'batch_ids.*.exists' => 'Lô ảnh không tồn tại.',
            'worker_id.required' => 'Chọn worker.',
            'start_at.after_or_equal' => 'Giờ bắt đầu đã qua.',
            'end_at.after_or_equal' => 'Giờ kết thúc phải sau giờ bắt đầu.',
            ...self::platformMessages(),
        ]);
        $worker = $publisher->findWorker($user, (int) $data['worker_id']);
        $batchIds = array_map('intval', array_values($data['batch_ids']));

        // Lô chưa có ảnh nào xong (đang tạo / lỗi hết / đã tắt hết): báo ngay thay vì để job bỏ qua
        $ready = ImageBatch::query()->where('user_id', $user->id)->whereIn('id', $batchIds)
            ->whereHas('images.output', fn ($q) => $q->usable())
            ->pluck('id')->all();
        $empty = array_values(array_diff($batchIds, $ready));
        if ($empty) {
            throw ValidationException::withMessages([
                'batch_ids' => 'Lô #'.implode(', #', $empty).' chưa có ảnh nào tạo xong để đăng.',
            ]);
        }

        $start = Carbon::parse($data['start_at'])->setTimezone(config('app.timezone'));
        $end = Carbon::parse($data['end_at'])->setTimezone(config('app.timezone'));
        $count = count($batchIds);
        $step = $count > 1 ? ($end->getTimestamp() - $start->getTimestamp()) / ($count - 1) : 0;
        $platforms = array_values(array_unique($data['platforms']));
        $aiContent = (bool) ($data['ai_content'] ?? false);

        $schedule = [];
        foreach ($batchIds as $i => $batchId) {
            $at = $start->copy()->addSeconds((int) round($step * $i));
            CreateScheduledBatchPost::dispatch(
                $user->id, $batchId, $worker->id, $platforms, $at->toIso8601String(), $aiContent,
                'schedule:'.$data['idempotency_key'].':'.$batchId,
            );
            $schedule[] = ['batch_id' => $batchId, 'scheduled_at' => $at->toIso8601String()];
        }

        return response()->json(['data' => ['queued' => $count, 'schedule' => $schedule]], 202);
    }

    /** "Đăng lại" (bài lỗi) / "Tạo lại" (worker nhận rồi im lặng, có thể đăng trùng): bài về chờ tới giờ. */
    public function retry(Request $request, int $post, SocialPublishJobService $publisher): SocialPostResource
    {
        return $this->resource($publisher->retry($request->user(), $this->findPost($request, $post)));
    }

    /**
     * "Viết lại bằng AI": trả { title, content, hashtags } để frontend điền vào form, KHÔNG lưu vào bài.
     * Chỉ khi bài đang chờ gửi. AI lỗi -> 503 AI_CONTENT_FAILED.
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
