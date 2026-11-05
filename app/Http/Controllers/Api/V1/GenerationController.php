<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationResource;
use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Product;
use App\Models\Worker;
use App\Services\AuditLogger;
use App\Services\GenerationInput;
use App\Services\GenerationService;
use App\Services\MediaStorage;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GenerationController extends Controller
{
    public function __construct(
        private GenerationService $generations,
        private SettingService $settings,
    ) {}

    public function store(Request $request): JsonResponse
    {
        // Chỉ nhận đúng các trường này. Giá, model, prompt... luôn đọc từ database.
        // Ảnh/giá trị đầu vào kiểm tra theo form của Creative (GenerationInput).
        $data = $request->validate([
            'creative_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$this->settings->int('generation.max_outputs_limit')],
            'idempotency_key' => ['required', 'string', 'max:100'],
            // Worker tạo ảnh người dùng chọn (note/chon-worker-tao-anh.txt ở repo frontend)
            'worker_id' => ['nullable', 'integer'],
        ]);

        $creative = AiCreative::query()->find($data['creative_id'])
            ?? throw BusinessException::creativeNotAvailable();
        $workerId = Worker::imageWorkerIdFor($request->user()->id, $data['worker_id'] ?? null);
        $input = GenerationInput::fromRequest($request, $creative, $this->settings->int('generation.input_max_size_kb'));

        $generation = $this->generations->create(
            $request->user(), $creative, $input, $data['quantity'], $data['idempotency_key'], [], null, $workerId,
        );

        return (new GenerationResource($generation->load('creative')))->response()->setStatusCode(202);
    }

    /**
     * Lịch sử mua. tab lọc theo ảnh, mỗi đơn chỉ kèm ảnh của tab đó, đơn không có ảnh nào của tab thì không trả về:
     * active = ảnh chưa tắt và không lỗi (gồm ảnh đang tạo), failed = ảnh tạo thất bại, disabled = ảnh người dùng đã tắt.
     * Không có tab: mọi đơn, kèm ảnh chưa tắt (như cũ, dùng cho ô chọn ảnh).
     * creative_id: chỉ đơn của creative đó. product_id: chỉ ảnh nằm trong các lô gắn sản phẩm đó (lô tự động / chọn sản phẩm).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'tab' => ['nullable', 'in:active,failed,disabled'],
            'creative_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
        ]);
        $tab = $data['tab'] ?? null;
        $userId = $request->user()->id;
        $productId = $data['product_id'] ?? null;
        $tabFilter = match ($tab) {
            'active' => fn ($q) => $q->whereNull('disabled_at')->where('status', '!=', OutputStatus::Failed),
            // Ảnh lỗi đã bấm "Tạo lại" (có output mới retry_of_output_id trỏ tới) thì không hiện nữa
            'failed' => fn ($q) => $q->where('status', OutputStatus::Failed)
                ->whereNotExists(fn ($s) => $s->from('generation_outputs as retries')
                    ->whereColumn('retries.retry_of_output_id', 'generation_outputs.id')),
            'disabled' => fn ($q) => $q->whereNotNull('disabled_at'),
            default => self::enabledOutputs(...),
        };
        $filter = function ($q) use ($tabFilter, $productId, $userId) {
            $tabFilter($q);
            if ($productId) {
                $q->whereIn('generation_outputs.id', fn ($s) => $s->select('image_batch_images.output_id')
                    ->from('image_batch_images')
                    ->join('image_batches', 'image_batches.id', '=', 'image_batch_images.image_batch_id')
                    ->where('image_batches.product_id', $productId)
                    ->where('image_batches.user_id', $userId));
            }
        };

        $generations = Generation::query()
            ->where('user_id', $userId)
            ->when($data['creative_id'] ?? null, fn ($q, $id) => $q->where('creative_id', $id))
            ->when($tab || $productId, fn ($q) => $q->whereHas('outputs', $filter))
            ->with(['creative', 'outputs' => $filter])
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 20), 50));

        return GenerationResource::collection($generations);
    }

    /**
     * Lựa chọn cho bộ lọc lịch sử mua: creative người dùng đã mua (theo tên) và sản phẩm của người dùng có lô ảnh.
     * { data: { creatives: [{ id, title }], products: [{ id, name }] } }
     */
    public function filters(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json(['data' => [
            'creatives' => AiCreative::query()
                ->whereIn('id', Generation::query()->where('user_id', $userId)->select('creative_id'))
                ->orderBy('title')
                ->get(['id', 'title']),
            'products' => Product::query()
                ->where('user_id', $userId)
                ->whereIn('id', fn ($q) => $q->select('product_id')->from('image_batches')
                    ->where('user_id', $userId)->whereNotNull('product_id'))
                ->latest('id')
                ->get(['id', 'name']),
        ]]);
    }

    public function show(Request $request, Generation $generation): GenerationResource
    {
        $this->authorizeOwner($request, $generation);

        return new GenerationResource($generation->load(['creative', 'outputs' => self::enabledOutputs(...)]));
    }

    /** Ảnh người dùng đã tắt không trả về (trừ tab "Đã tắt"). */
    private static function enabledOutputs($query): void
    {
        $query->whereNull('disabled_at');
    }

    /** Bản rút gọn để frontend polling. */
    public function status(Request $request, Generation $generation): JsonResponse
    {
        $this->authorizeOwner($request, $generation);
        $media = app(MediaStorage::class);

        return response()->json(['data' => [
            'id' => $generation->id,
            'status' => $generation->status,
            'success_count' => $generation->success_count,
            'failed_count' => $generation->failed_count,
            'outputs' => $generation->outputs()->whereNull('disabled_at')->get()->map(fn (GenerationOutput $o) => [
                'id' => $o->id,
                'position' => $o->position,
                'status' => $o->status,
                'image_url' => $o->status === OutputStatus::Completed ? $media->url($o->image_path) : null,
                'thumb_url' => $o->status === OutputStatus::Completed ? $media->url($o->thumbPathOrOriginal()) : null,
                'error_code' => $o->error_code,
            ]),
        ]]);
    }

    public function retry(Request $request, Generation $generation): JsonResponse
    {
        $this->authorizeOwner($request, $generation);

        $data = $request->validate([
            'output_ids' => ['required', 'array', 'min:1', 'max:'.$this->settings->int('generation.max_outputs_limit')],
            'output_ids.*' => ['integer', 'distinct'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $retry = $this->generations->retry($request->user(), $generation, $data['output_ids'], $data['idempotency_key']);

        return (new GenerationResource($retry->load('creative')))->response()->setStatusCode(202);
    }

    public function download(Request $request, Generation $generation, GenerationOutput $output): JsonResponse
    {
        $this->authorizeOwner($request, $generation);
        abort_unless($output->generation_id === $generation->id && $output->isUsable(), 404);

        $extension = pathinfo($output->image_path, PATHINFO_EXTENSION);
        $filename = "ai90-{$generation->id}-{$output->position}.{$extension}";

        return response()->json(['data' => [
            'url' => app(MediaStorage::class)->url($output->image_path, $filename),
            'filename' => $filename,
            'expires_in_minutes' => config('ai90.signed_url_ttl_minutes'),
        ]]);
    }

    /**
     * Người dùng tự tắt ảnh đã tạo xong (không thích, ảnh lỗi...). Không hoàn tiền. Ảnh tắt rồi không còn trả về ở lịch
     * sử, không tải về, không chọn cho bài đăng và không gửi cho worker; bài đăng / lô đang chứa ảnh thì bỏ qua ảnh đó.
     * Gọi lại với ảnh đã tắt vẫn trả 204.
     */
    public function disableOutput(Request $request, Generation $generation, GenerationOutput $output): Response
    {
        $this->authorizeOwner($request, $generation);
        abort_unless($output->generation_id === $generation->id, 404);
        if ($output->status !== OutputStatus::Completed) {
            throw new BusinessException('IMAGE_NOT_READY', 'Chỉ tắt được ảnh đã tạo xong.', 409);
        }

        if (! $output->disabled_at) {
            $output->update(['disabled_at' => now()]);
            app(AuditLogger::class)->log('generation_output.disable', $generation, ['output_id' => $output->id], $request->user()->id);
        }

        return response()->noContent();
    }

    /** Bật lại ảnh đã tắt: dùng lại được như cũ. Ảnh đang bật vẫn trả 204. */
    public function enableOutput(Request $request, Generation $generation, GenerationOutput $output): Response
    {
        $this->authorizeOwner($request, $generation);
        abort_unless($output->generation_id === $generation->id, 404);

        if ($output->disabled_at) {
            $output->update(['disabled_at' => null]);
            app(AuditLogger::class)->log('generation_output.enable', $generation, ['output_id' => $output->id], $request->user()->id);
        }

        return response()->noContent();
    }

    private function authorizeOwner(Request $request, Generation $generation): void
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
    }
}
