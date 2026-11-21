<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OutputStatus;
use App\Http\Controllers\Controller;
use App\Models\ChannelTextTemplate;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\ImageBatch;
use App\Models\ImageBatchImage;
use App\Models\Product;
use App\Models\Worker;
use App\Services\MediaStorage;
use App\Services\ShopImageLibrary;
use App\Services\WorkerJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * API cho worker tạo ảnh: nhận 1 job (= 1 ảnh) và trả kết quả.
 */
class WorkerJobController extends Controller
{
    public function __construct(private WorkerJobService $jobs) {}

    public function next(Request $request, MediaStorage $media): JsonResponse|Response
    {
        $output = $this->jobs->claim($this->worker($request));
        if (! $output) {
            return response()->noContent();
        }

        $generation = $output->generation()->with(['model', 'creative.config', 'user.creatorProfile'])->firstOrFail();
        if ($generation->isOutfit()) {
            return response()->json(['data' => $this->outfitJob($output, $generation, $media)]);
        }

        // id: mã job để gửi kết quả (POST /jobs/{id}); request: dữ liệu gửi tới model.
        // logo: worker chèn vào ảnh trước khi gửi kết quả. Để ngoài request vì worker dán nguyên request cho model.
        // Phải trả kết quả trong ai90.worker.lease_minutes, quá hạn job được giao cho worker khác.
        return response()->json(['data' => [
            'id' => $output->id,
            'request' => $this->request($generation, $media),
            'logo' => $this->logo($generation, $media),
        ]]);
    }

    /**
     * Logo = ảnh đại diện HIỆN TẠI của shop chủ generation; null khi không chèn
     * hoặc không còn logo.
     */
    private function logo(Generation $generation, MediaStorage $media): ?array
    {
        $path = $generation->insert_logo ? $generation->user?->logoPath() : null;

        return $path ? ['url' => $media->url($path)] : null;
    }

    /**
     * Yêu cầu gửi tới model dạng phẳng: mỗi ảnh đầu vào một khoá camelCase (product_image -> productImage),
     * gồm cả ảnh nguyên liệu ẩn của Creator, kèm prompt đã ghép, tỉ lệ khung hình và tên model.
     */
    private function request(Generation $generation, MediaStorage $media): array
    {
        $paths = [...($generation->asset_paths ?? []), ...($generation->input_paths ?? [])];
        // Cột JSON của MySQL không giữ thứ tự khoá -> xếp lại theo thứ tự trường trong form
        $order = array_flip(array_column($generation->creative->config?->input_schema ?? [], 'key'));
        uksort($paths, fn ($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        $images = [];
        foreach ($paths as $key => $path) {
            $images[Str::camel($key)] = $media->url($path);
        }

        return [
            ...$images,
            'prompt' => $generation->prompt_snapshot,
            'aspectRatio' => $generation->aspect_ratio,
            'modelDisplayName' => $generation->model->name,
        ];
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $output = GenerationOutput::query()->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'image' => ['required_if:status,completed', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('ai90.worker.output_max_size_kb')],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'error_message' => ['nullable', 'string', 'max:490'],
            // Ảnh bìa lô phối đồ: hook bìa -> tiêu đề lô (ảnh outfit sau chọn outfit theo tiêu đề này)
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $worker = $this->worker($request);
        if ($data['status'] === 'completed') {
            $this->jobs->complete($worker, $output, $data['image']);
            $this->saveBatchTitle($output, $data['title'] ?? null);
        } else {
            $this->jobs->fail($worker, $output, $data['error_code'], $data['error_message'] ?? null);
        }

        return response()->json(['data' => ['id' => $output->id, 'status' => $output->fresh()->status]]);
    }

    /**
     * Ảnh xong kèm title -> tiêu đề lô (bỏ dấu * tô màu từ khoá). Được đặt tiêu đề: ảnh bìa, và ảnh outfit ĐẦU TIÊN
     * (vị trí 2) của lô không dùng sản phẩm (ảnh này làm trước bìa, 2026-11-16). Ảnh khác: bỏ qua title.
     */
    private function saveBatchTitle(GenerationOutput $output, ?string $title): void
    {
        $title = trim(str_replace('*', '', (string) $title));
        $generation = $output->generation;
        if ($title === '' || ! $generation || ! in_array($generation->kind, Generation::OUTFIT_KINDS, true)) {
            return;
        }
        $image = ImageBatchImage::query()->where('output_id', $output->id)->first();
        if (! $image) {
            return;
        }
        $firstOutfit = $generation->kind === Generation::KIND_OUTFIT && $image->position === 2 && (int) $generation->product_id === 0;
        if ($generation->kind === Generation::KIND_OUTFIT_COVER || $firstOutfit) {
            // Lô có hook cố định (bài kế hoạch lookbook): giữ nguyên tiêu đề, không để worker ghi đè
            ImageBatch::query()->whereKey($image->image_batch_id)->where('hook_locked', false)->update(['title' => $title]);
        }
    }

    /**
     * Ảnh bìa lô không dùng sản phẩm: ảnh outfit đầu tiên (vị trí 2) đã xong của lô -> worker dựng bìa từ ảnh này + tiêu đề
     * lô theo mẫu bìa. Chưa xong / lỗi / không phải bìa lô không sản phẩm -> null (worker tự vẽ bìa như cũ).
     */
    private function firstImage(?ImageBatchImage $image, Generation $generation, MediaStorage $media): ?array
    {
        if (! $image || $generation->kind !== Generation::KIND_OUTFIT_COVER || (int) $generation->product_id > 0) {
            return null;
        }
        $first = ImageBatchImage::query()->where('image_batch_id', $image->image_batch_id)->where('position', 2)
            ->with('output')->first()?->output;
        if (! $first || $first->status !== OutputStatus::Completed || ! $first->image_path) {
            return null;
        }

        return ['id' => $first->id, 'url' => $media->url($first->image_path)];
    }

    /**
     * GET /api/v1/jobs/{id}/batch: lô của job worker đang / đã làm: id, title (hook bìa; null khi bìa chưa xong / lỗi),
     * position, total. Ảnh outfit đọc tiêu đề lô để chọn outfit đúng chủ đề.
     */
    public function batch(Request $request, int $id): JsonResponse
    {
        $output = GenerationOutput::query()->whereKey($id)->where('worker_id', $this->worker($request)->id)->firstOrFail();
        $image = ImageBatchImage::query()->where('output_id', $output->id)->firstOrFail();

        return response()->json(['data' => $this->batchInfo($image)]);
    }

    private function batchInfo(ImageBatchImage $image): array
    {
        $batch = ImageBatch::query()->find($image->image_batch_id, ['id', 'title', 'hook_locked', 'outfit_tags', 'outfit_labels', 'text_template_id']);
        // Template chữ của kênh (đọc cả khi chủ shop đã bỏ template sau khi tạo lô: lô vẫn in theo kiểu đã chốt)
        $template = $batch?->text_template_id
            ? ChannelTextTemplate::query()->find($batch->text_template_id, ['id', 'name', 'style'])
            : null;

        return [
            'id' => $image->image_batch_id,
            'title' => $batch?->title,
            // true: lô có sẵn hook (bài kế hoạch lookbook) -> worker KHÔNG tạo hook, dùng title làm hook bìa;
            // outfit_tags: hashtag chủ đề để tìm outfit trên OOTD (a90-fe note/xay-kenh-tiktok/bai-viet-mau/lookbook.txt)
            'hook_locked' => (bool) $batch?->hook_locked,
            'outfit_tags' => $batch?->outfit_tags ?? [],
            // Bài outfit_by_day: nhãn ngày in lên ảnh outfit thay "Outfit N" (phần tử 0 = ảnh vị trí 2), worker in theo
            // text_template; rỗng = lô thường (note/xay-kenh-tiktok/bai-viet-mau/outfit_by_day.txt)
            'outfit_labels' => $batch?->outfit_labels ?? [],
            // Worker phối đồ v2 in hook (title) lên ảnh bìa theo style này (style như ChannelTextTemplate); null = lô
            // không có template chữ
            'text_template' => $template ? ['id' => $template->id, 'name' => $template->name, 'style' => $template->style] : null,
            'position' => $image->position,
            'total' => ImageBatchImage::query()->where('image_batch_id', $image->image_batch_id)->count(),
        ];
    }

    /** POST /api/v1/jobs/{id}/extend: gia hạn job đang làm (WorkerJobService::extend). */
    public function extend(Request $request, int $id): JsonResponse
    {
        $output = $this->jobs->extend($this->worker($request), GenerationOutput::query()->findOrFail($id));

        return response()->json(['data' => ['id' => $output->id, 'lease_expires_at' => $output->lease_expires_at?->toIso8601String()]]);
    }

    /**
     * Job lô phối đồ: worker (skill Claude) tự chọn / tạo outfit, tạo ảnh, in hook rồi gửi ảnh có hook.
     * product.images: mọi ảnh sản phẩm theo field_key; batch.position 1 = ảnh bìa.
     */
    private function outfitJob(GenerationOutput $output, Generation $generation, MediaStorage $media): array
    {
        $image = ImageBatchImage::query()->where('output_id', $output->id)->first();
        $product = Product::query()->with('images', 'hashtags')->find($generation->product_id);

        return [
            'id' => $output->id,
            'kind' => $generation->kind,
            // batch.title: tiêu đề lô = hook bìa (ảnh bìa làm trước); ảnh outfit chọn outfit theo chủ đề này
            'batch' => $image ? $this->batchInfo($image) : null,
            'user_id' => $generation->user_id,
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'prompt_note' => $product->prompt_note,
                'hashtags' => $product->hashtags->pluck('name')->values(),
                'images' => $product->images->map(fn ($i) => ['field_key' => $i->field_key, 'url' => $media->url($i->path)])->values(),
            ] : null,
            // Ảnh trong lịch sử tạo ảnh của sản phẩm (ảnh creative đã xong, chưa tắt, mới nhất trước): worker chọn ảnh
            // chụp cận giày làm ảnh giày cho bìa + ChatGPT thay cho ảnh sản phẩm gốc (yêu cầu người dùng 2026-11-14)
            'history_images' => $product ? $this->historyImages($generation->user_id, $product->id, $media) : [],
            // false: lô không dùng ảnh sản phẩm (công tắc trên /batches/auto) -> worker vẽ theo ảnh gốc outfit + người mẫu,
            // ảnh bìa cũng vẽ mới (outfit khác các ảnh outfit của lô)
            'use_product' => (int) $generation->product_id > 0,
            // Bìa lô không dùng sản phẩm: ảnh outfit đầu tiên đã xong của lô (bìa = ảnh này + tiêu đề lô theo mẫu bìa)
            'first_image' => $this->firstImage($image, $generation, $media),
            'logo' => $this->logo($generation, $media),
        ];
    }

    public const HISTORY_IMAGES = 30;

    private function historyImages(int $userId, int $productId, MediaStorage $media): array
    {
        return app(ShopImageLibrary::class)->productImages($userId, $productId)
            ->whereHas('generation', fn ($q) => $q->where('kind', Generation::KIND_CREATIVE))
            ->with('generation.creative:id,title')
            ->orderByDesc('id')->limit(self::HISTORY_IMAGES)->get()
            ->map(fn (GenerationOutput $o) => [
                'id' => $o->id,
                'url' => $media->url($o->image_path),
                'creative' => $o->generation?->creative?->title,
            ])->values()->all();
    }

    private function worker(Request $request): Worker
    {
        return $request->attributes->get('worker');
    }
}
