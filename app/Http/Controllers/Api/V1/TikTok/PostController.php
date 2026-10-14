<?php

namespace App\Http\Controllers\Api\V1\TikTok;

use App\Enums\TikTokPostStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TikTokPostResource;
use App\Models\AiCreative;
use App\Models\Hashtag;
use App\Models\TikTokPost;
use App\Models\TikTokPostImage;
use App\Services\GenerationInput;
use App\Services\SettingService;
use App\Services\TikTok\TikTokPostService;
use App\Services\TikTok\TikTokPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Bài đăng TikTok của người dùng, xem note/nghiep-vu-dang-bai-tiktik.txt (mục 5).
 * Bài của người khác -> 404.
 */
class PostController extends Controller
{
    public function __construct(
        private TikTokPostService $posts,
        private SettingService $settings,
    ) {}

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

    /** Bước 2b: dùng ảnh đã có (không tính phí). */
    public function storeFromImages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.TikTokPost::MAX_IMAGES],
            'images.*.generation_id' => ['required', 'integer'],
            'images.*.output_id' => ['required', 'integer', 'distinct'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            ...self::contentRules(required: false),
        ], [
            'images.max' => 'Tối đa '.TikTokPost::MAX_IMAGES.' ảnh mỗi bài.',
            'images.*.output_id.distinct' => 'Ảnh bị chọn trùng.',
            ...self::contentMessages(),
        ]);

        $post = $this->posts->createFromImages($request->user(), array_values($data['images']), $data['idempotency_key'], $data);

        return $this->resource($post)->response()->setStatusCode(201);
    }

    /** Bước 2a: tạo ảnh mới, mỗi mẫu 1 ảnh, inputs từng mẫu theo input_schema (như POST /generations). */
    public function storeFromCreatives(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.TikTokPost::MAX_CREATIVES],
            'items.*.creative_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            ...self::contentRules(required: false),
        ], [
            'items.max' => 'Tối đa '.TikTokPost::MAX_CREATIVES.' mẫu mỗi lần.',
            ...self::contentMessages(),
        ]);

        $items = array_values($data['items']);
        $creatives = AiCreative::query()
            ->with(['config', 'model'])
            ->whereIn('id', array_column($items, 'creative_id'))
            ->get()
            ->keyBy('id');

        // Kiểm tra inputs mọi mẫu một lần để trả đủ lỗi: errors["items.0.inputs.product"]
        $maxKb = $this->settings->int('generation.input_max_size_kb');
        $rules = [];
        foreach ($items as $i => $item) {
            $creative = $creatives->get((int) $item['creative_id']) ?? throw BusinessException::creativeNotAvailable();
            $rules += GenerationInput::rules($creative, $maxKb, "items.{$i}.");
        }
        $validated = $request->validate($rules);

        $resolved = [];
        foreach ($items as $i => $item) {
            $creative = $creatives->get((int) $item['creative_id']);
            $resolved[] = ['creative' => $creative, 'input' => GenerationInput::fromValidated($validated['items'][$i]['inputs'], $creative)];
        }

        $post = $this->posts->createFromCreatives($request->user(), $resolved, $data['idempotency_key'], $data);

        return $this->resource($post)->response()->setStatusCode(201);
    }

    /** Popup "Sửa nguyên liệu": nguyên liệu đã dùng để tạo ảnh. */
    public function imageInputs(Request $request, int $post, int $image): JsonResponse
    {
        $image = $this->findImage($this->findPost($request, $post), $image);

        return response()->json(['data' => $this->posts->imageInputs($image)]);
    }

    /**
     * "Tạo lại" (JSON { idempotency_key }) hoặc "Sửa nguyên liệu" (multipart inputs[...] + keep_files[key][]).
     */
    public function regenerate(Request $request, int $post, int $image): JsonResponse
    {
        $post = $this->findPost($request, $post);
        $image = $this->findImage($post, $image);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'keep_files' => ['nullable', 'array'],
        ]);

        $input = null;
        $kept = [];
        if ($request->has('inputs') || $request->has('keep_files')) {
            $this->posts->assertEditable($post);
            $creative = $image->generation?->creative()->with(['config', 'model'])->first()
                ?? throw new BusinessException('IMAGE_HAS_NO_CREATIVE', 'Không xác định được mẫu đã tạo ra ảnh này.', 409);

            $uploaded = array_keys(array_filter((array) $request->file('inputs', []), fn ($f) => $f instanceof UploadedFile));
            $kept = $this->posts->keptPaths($image, $data['keep_files'] ?? [], $uploaded);
            $validated = $request->validate(GenerationInput::rules($creative, $this->settings->int('generation.input_max_size_kb'), '', array_keys($kept)));
            $input = GenerationInput::fromValidated($validated['inputs'], $creative);
        }

        $image = $this->posts->regenerate($request->user(), $post, $image, $input, $kept, $data['idempotency_key']);

        return response()->json(['data' => TikTokPostResource::image($image->load(['output', 'generation.creative']))], 202);
    }

    /** "Duyệt và đăng": body là toàn bộ chỉnh sửa trên trang, ảnh được gửi vào hộp thư TikTok. */
    public function publish(Request $request, int $post, TikTokPublisher $publisher): TikTokPostResource
    {
        $post = $this->findPost($request, $post);
        $data = $request->validate([
            ...self::contentRules(required: true),
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

    /** Tiêu đề / nội dung / hashtag: không bắt buộc khi tạo bài, tiêu đề bắt buộc khi đăng. */
    private static function contentRules(bool $required): array
    {
        return [
            'title' => [$required ? 'required' : 'nullable', 'string', 'max:'.TikTokPost::TITLE_MAX],
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

    private function findImage(TikTokPost $post, int $id): TikTokPostImage
    {
        return $post->images()->with('generation')->findOrFail($id);
    }

    private function resource(TikTokPost $post): TikTokPostResource
    {
        return new TikTokPostResource($post->fresh(TikTokPostResource::RELATIONS));
    }
}
