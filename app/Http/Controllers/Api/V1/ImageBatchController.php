<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ImageBatchResource;
use App\Http\Resources\SocialPostResource;
use App\Models\AiCreative;
use App\Models\ImageBatch;
use App\Models\ImageBatchImage;
use App\Models\Product;
use App\Models\Worker;
use App\Services\AutoBatchService;
use App\Services\GenerationInput;
use App\Services\ImageBatchService;
use App\Services\MediaStorage;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Lô ảnh của người dùng, xem note/tach-lo-anh-dang-tiktok.txt. Lô của người khác -> 404.
 */
class ImageBatchController extends Controller
{
    private const CROP_MAX_KB = 20480;

    public function __construct(
        private ImageBatchService $batches,
        private SettingService $settings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $batches = ImageBatch::query()
            ->where('user_id', $request->user()->id)
            ->withSummary()
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return ImageBatchResource::collection($batches);
    }

    public function show(Request $request, int $batch): ImageBatchResource
    {
        return $this->resource($this->findBatch($request, $batch));
    }

    /** Mỗi mẫu một lần, quantity ảnh từ cùng nguyên liệu; inputs từng mẫu theo input_schema (như POST /generations). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.creative_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'insert_logo' => ['nullable', 'boolean'],
            'worker_id' => ['nullable', 'integer'],
            // Sản phẩm: chỉ lưu vào generations.product_id của mọi ảnh trong lô, không gắn vào lô (image_batches.product_id)
            'product_id' => ['nullable', 'integer'],
        ], [
            'items.*.creative_id.distinct' => 'Mỗi mẫu chỉ được chọn một lần trong lô.',
        ]);

        $insertLogo = $this->insertLogo($request, $data);
        $workerId = Worker::imageWorkerIdFor($request->user()->id, $data['worker_id'] ?? null);
        $productId = Product::resolveAccessibleId($request->user(), $data['product_id'] ?? null);

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
            $resolved[] = [
                'creative' => $creative,
                'input' => GenerationInput::fromValidated($validated['items'][$i]['inputs'], $creative),
                'quantity' => (int) $item['quantity'],
            ];
        }

        $batch = $this->batches->create($request->user(), $resolved, $data['idempotency_key'], $insertLogo, [], $workerId, $productId);

        return $this->resource($batch)->response()->setStatusCode(201);
    }

    /** Xem trước "Tạo tự động" (note/auto-tao-lo.txt ở repo frontend): count lô sẽ tạo, không tạo gì. */
    public function autoPreview(Request $request, AutoBatchService $auto): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.AutoBatchService::MAX_COUNT],
            'seed' => ['nullable', 'integer', 'min:0', 'max:'.AutoBatchService::MAX_SEED],
            // Chọn sản phẩm thủ công; không gửi = mọi sản phẩm. Id không thuộc người dùng thì bị bỏ qua
            'product_ids' => ['nullable', 'array', 'max:'.AutoBatchService::MAX_COUNT],
            'product_ids.*' => ['integer'],
        ]);
        $user = $request->user();
        $media = app(MediaStorage::class);
        $preview = $auto->preview($user, (int) $data['count'], isset($data['seed']) ? (int) $data['seed'] : null, self::productIds($data));

        $plans = array_map(fn (array $plan) => [
            'product' => self::productSummary($plan['product'], $media),
            'group' => ['id' => $plan['group']->id, 'name' => $plan['group']->name],
            'creatives' => array_map(fn (array $item) => ImageBatchService::creativeSummary($item['creative']), $plan['items']),
        ], $preview['plans']);

        return response()->json(['data' => [
            'seed' => $preview['seed'],
            'batches' => $plans,
        ]]);
    }

    /** "Tạo tự động": tạo lần lượt tới count lô, hết cặp hợp lệ thì dừng. */
    public function autoStore(Request $request, AutoBatchService $auto): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.AutoBatchService::MAX_COUNT],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'insert_logo' => ['nullable', 'boolean'],
            'seed' => ['nullable', 'integer', 'min:0', 'max:'.AutoBatchService::MAX_SEED],
            'worker_id' => ['nullable', 'integer'],
            'product_ids' => ['nullable', 'array', 'max:'.AutoBatchService::MAX_COUNT],
            'product_ids.*' => ['integer'],
        ]);

        $seed = isset($data['seed']) ? (int) $data['seed'] : null;
        $insertLogo = $this->insertLogo($request, $data);
        $workerId = Worker::imageWorkerIdFor($request->user()->id, $data['worker_id'] ?? null);
        $result = $auto->create($request->user(), (int) $data['count'], $insertLogo, $data['idempotency_key'], $seed, $workerId, self::productIds($data));
        $batches = ImageBatch::query()
            ->whereKey(array_map(fn (ImageBatch $b) => $b->id, $result['batches']))
            ->withSummary()
            ->orderBy('id')
            ->get();

        return response()->json(['data' => [
            'batches' => ImageBatchResource::collection($batches)->resolve($request),
            'requested' => (int) $data['count'],
            'stopped' => $result['stopped'],
        ]], 201);
    }

    /** Popup "Sửa nguyên liệu": nguyên liệu đã dùng để tạo ảnh. */
    public function imageInputs(Request $request, int $batch, int $image): JsonResponse
    {
        $image = $this->findImage($this->findBatch($request, $batch), $image);

        return response()->json(['data' => $this->batches->imageInputs($image)]);
    }

    /**
     * "Tạo lại" (JSON { idempotency_key }) hoặc "Sửa nguyên liệu" (multipart inputs[...] + keep_files[key][]).
     */
    public function regenerate(Request $request, int $batch, int $image): JsonResponse
    {
        $batch = $this->findBatch($request, $batch);
        $image = $this->findImage($batch, $image);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'keep_files' => ['nullable', 'array'],
        ]);

        $input = null;
        $kept = [];
        if ($request->has('inputs') || $request->has('keep_files')) {
            $creative = $image->generation?->creative()->with(['config', 'model'])->first()
                ?? throw new BusinessException('IMAGE_HAS_NO_CREATIVE', 'Không xác định được mẫu đã tạo ra ảnh này.', 409);

            $uploaded = array_keys(array_filter((array) $request->file('inputs', []), fn ($f) => $f instanceof UploadedFile));
            $kept = $this->batches->keptPaths($image, $data['keep_files'] ?? [], $uploaded);
            $validated = $request->validate(GenerationInput::rules($creative, $this->settings->int('generation.input_max_size_kb'), '', array_keys($kept)));
            $input = GenerationInput::fromValidated($validated['inputs'], $creative);
        }

        $image = $this->batches->regenerate($request->user(), $batch, $image, $input, $kept, $data['idempotency_key']);

        return response()->json(['data' => SocialPostResource::image($image->load(['output', 'generation.creative']))], 202);
    }

    /** "Cắt ảnh": multipart image = ảnh đã cắt trên trình duyệt, ghi đè ảnh gốc. */
    public function crop(Request $request, int $batch, int $image): JsonResponse
    {
        $batch = $this->findBatch($request, $batch);
        $image = $this->findImage($batch, $image);
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::CROP_MAX_KB],
        ], [
            'image.max' => 'Ảnh đã cắt tối đa '.(self::CROP_MAX_KB / 1024).'MB.',
        ]);

        $image = $this->batches->crop($request->user(), $batch, $image, $request->file('image'));

        return response()->json(['data' => SocialPostResource::image($image->load(['output', 'generation.creative']))]);
    }

    public function destroyImage(Request $request, int $batch, int $image): Response
    {
        $batch = $this->findBatch($request, $batch);
        $this->batches->deleteImage($request->user(), $batch, $this->findImage($batch, $image));

        return response()->noContent();
    }

    /** "Hủy ảnh đang chờ": ảnh chưa worker nào nhận thành thất bại. */
    public function cancel(Request $request, int $batch): JsonResponse
    {
        $batch = $this->findBatch($request, $batch);
        $cancelled = $this->batches->cancelQueued($request->user(), $batch);

        return $this->resource($batch)->additional(['meta' => ['cancelled' => $cancelled]])->response();
    }

    public function destroy(Request $request, int $batch): Response
    {
        $this->batches->delete($request->user(), $this->findBatch($request, $batch));

        return response()->noContent();
    }

    /**
     * Xoá nhiều lô: batch_ids (lô của mình) hoặc all = true (mọi lô). Lô còn ảnh đang tạo bị bỏ qua.
     * Ảnh trong lịch sử tạo ảnh và bài đăng đã tạo vẫn giữ.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'all' => ['nullable', 'boolean'],
            'batch_ids' => ['required_unless:all,true', 'array', 'max:500'],
            'batch_ids.*' => ['integer', 'distinct'],
        ], [
            'batch_ids.required_unless' => 'Chọn ít nhất một lô.',
        ]);
        $ids = ($data['all'] ?? false) ? null : array_map('intval', $data['batch_ids']);

        return response()->json(['data' => $this->batches->deleteMany($request->user(), $ids)]);
    }

    /** Logo = ảnh đại diện shop (note/logo-tao-anh.txt). */
    private function insertLogo(Request $request, array $data): bool
    {
        $insertLogo = (bool) ($data['insert_logo'] ?? false);
        $user = $request->user();
        if ($insertLogo && ! $user->logoPath()) {
            throw ValidationException::withMessages(['insert_logo' => $user->creatorProfile
                ? 'Shop chưa có logo. Hãy thêm ảnh đại diện shop để dùng tính năng này.'
                : 'Bạn phải tạo shop để sử dụng tính năng này.']);
        }

        return $insertLogo;
    }

    /** @return int[]|null */
    private static function productIds(array $data): ?array
    {
        return empty($data['product_ids']) ? null : array_values(array_unique(array_map('intval', $data['product_ids'])));
    }

    private static function productSummary(Product $product, MediaStorage $media): array
    {
        return ['id' => $product->id, 'name' => $product->name, 'cover_url' => $media->url($product->images->first()?->thumbPathOrOriginal())];
    }

    private function findBatch(Request $request, int $id): ImageBatch
    {
        return ImageBatch::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function findImage(ImageBatch $batch, int $id): ImageBatchImage
    {
        return $batch->images()->with('generation')->findOrFail($id);
    }

    private function resource(ImageBatch $batch): ImageBatchResource
    {
        return new ImageBatchResource(ImageBatch::query()->withProductId()->with(ImageBatchResource::RELATIONS)->withCount('posts')->findOrFail($batch->id));
    }
}
