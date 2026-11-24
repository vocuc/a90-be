<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\ChannelTextTemplate;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\ImageBatch;
use App\Models\ContentJob;
use App\Models\ImageBatchImage;
use App\Models\ImageJob;
use App\Models\PlanJob;
use App\Models\Product;
use App\Models\SocialPublishJob;
use App\Models\TagJob;
use App\Models\TopicJob;
use App\Models\Worker;
use App\Models\WorkerJob;
use App\Services\Channel\ContentJobService;
use App\Services\Channel\PlanJobService;
use App\Services\Channel\TagJobService;
use App\Services\Channel\TopicJobService;
use App\Services\MediaStorage;
use App\Services\ShopImageLibrary;
use App\Services\Social\SocialPublishJobService;
use App\Services\WorkerJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API CHUNG cho mọi worker (a90-fe note/gop-viec-worker.txt, chốt 2026-11-21): mọi việc lưu ở worker_jobs, phân biệt
 * bằng type = loại worker (image, outfit, content, publish). Worker nhận việc đúng loại của mình rồi trả kết quả.
 * Thay các API cũ /v1/jobs, /v1/content-jobs, /v1/post-jobs (đã bỏ).
 */
class WorkerJobController extends Controller
{
    public function __construct(
        private WorkerJobService $images,
        private ContentJobService $content,
        private SocialPublishJobService $publish,
        private TopicJobService $topics,
        private TagJobService $tags,
        private PlanJobService $plans,
    ) {}

    /**
     * GET /api/v1/worker-jobs: việc kế tiếp đúng loại của worker. 204: không có việc.
     * 200 { data: { id (mã việc worker_jobs), type: image|outfit|content|publish, ...dữ liệu theo loại } }
     *   image:   { output_id, request (dữ liệu gửi model), logo }
     *   outfit:  { output_id, kind, batch, product, history_images, use_product, first_image, logo, user_id }
     *   content: { request: { post_id, sample_key, sample_name, cover_collage, hook, images } }
     *   publish: { request: { type: photo|video, platforms, ... } }
     *   topic:   { request: { count, note, today, channel, persona, post_samples, holidays, existing_topics, tag_options,
     *              product_tags } } (worker lập chủ đề, skill Claude /lap-chu-de)
     *   tag:     { request: { products: [{ id, name, prompt_note, cover: { image_id, thumb_url }, ai_label, tags,
     *              tag_sources }], tag_options, label_max } } (worker gắn tag, skill Claude /gan-tag-san-pham)
     *   plan:    { request: { channel_id, channel_name, note } } (điều phối chạy skill Claude /lap-ke-hoach <id kênh>)
     */
    public function next(Request $request, MediaStorage $media): JsonResponse|Response
    {
        $worker = $this->worker($request);
        // ?types=content,tag: worker điều phối chỉ hỏi loại máy còn chỗ trống (a90-fe note/worker-dieu-phoi.txt)
        $types = $worker->jobTypes();
        if ($request->filled('types')) {
            $types = array_values(array_intersect(array_map('trim', explode(',', (string) $request->query('types'))), $types));
        }
        foreach ($types as $type) {
            if ($data = $this->claimType($worker, $type, $media)) {
                return response()->json(['data' => $data]);
            }
        }

        return response()->noContent();
    }

    private function claimType(Worker $worker, string $type, MediaStorage $media): ?array
    {
        return match ($type) {
            Worker::TYPE_IMAGE, Worker::TYPE_OUTFIT => $this->nextImage($worker, $media),
            Worker::TYPE_CONTENT => ($job = $this->content->claim($worker))
                ? ['id' => $job->id, 'type' => WorkerJob::TYPE_CONTENT, 'request' => $this->content->request($job)] : null,
            Worker::TYPE_PUBLISH => ($job = $this->publish->claim($worker))
                ? ['id' => $job->id, 'type' => WorkerJob::TYPE_PUBLISH, 'request' => $this->publish->request($job)] : null,
            Worker::TYPE_TOPIC => ($job = $this->topics->claim($worker))
                ? ['id' => $job->id, 'type' => WorkerJob::TYPE_TOPIC, 'request' => $this->topics->request($job)] : null,
            Worker::TYPE_TAG => ($job = $this->tags->claim($worker))
                ? ['id' => $job->id, 'type' => WorkerJob::TYPE_TAG, 'request' => $this->tags->request($job)] : null,
            Worker::TYPE_PLAN => ($job = $this->plans->claim($worker))
                ? ['id' => $job->id, 'type' => WorkerJob::TYPE_PLAN, 'request' => $this->plans->request($job)] : null,
            default => throw new BusinessException('WRONG_WORKER_TYPE', 'Loại worker không nhận việc.', 403),
        };
    }

    private function nextImage(Worker $worker, MediaStorage $media): ?array
    {
        $job = $this->images->claim($worker);
        if (! $job) {
            return null;
        }
        $output = $job->output;
        $generation = $output->generation()->with(['model', 'creative.config', 'user.creatorProfile'])->firstOrFail();
        if ($generation->isOutfit()) {
            return [...$this->outfitJob($output, $generation, $media), 'id' => $job->id, 'type' => WorkerJob::TYPE_OUTFIT, 'output_id' => $output->id];
        }

        // request: dữ liệu gửi tới model. logo: worker chèn vào ảnh trước khi gửi kết quả (để ngoài request vì worker
        // dán nguyên request cho model). Phải trả kết quả trong ai90.worker.lease_minutes, quá hạn việc giao worker khác.
        return [
            'id' => $job->id,
            'type' => WorkerJob::TYPE_IMAGE,
            'output_id' => $output->id,
            'request' => $this->request($generation, $media),
            'logo' => $this->logo($generation, $media),
        ];
    }

    /**
     * POST /api/v1/worker-jobs/{id}: status=completed|failed (+ error_code, error_message khi failed) và kết quả theo loại:
     *   image / outfit: file image (+ title: hook bìa lô phối đồ)
     *   content: files[0..n-1] (ảnh hoàn chỉnh theo thứ tự slide; cover_collage thì thêm bìa ở đầu)
     *   publish: post_url
     *   topic: topics [{ name, tags }] -> data.result { created, skipped, errors }
     *   tag: items [{ id, image_id, ai_label, tags }] -> data.result { updated, skipped (trường chủ shop giữ), errors }
     *   plan: summary (báo cáo cuối của Claude) -> data.result { summary, plan_id, plan_status }
     * 409 JOB_NOT_ASSIGNED: việc không còn của worker (quá hạn, bị thay, sai loại).
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $worker = $this->worker($request);
        $job = WorkerJob::query()->findOrFail($id);
        if (! $worker->hasType($job->type)) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }
        $base = [
            'status' => ['required', 'in:completed,failed'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'error_message' => ['nullable', 'string', 'max:490'],
        ];

        if ($job->type === WorkerJob::TYPE_PLAN) {
            $data = $request->validate([...$base, 'summary' => ['nullable', 'string', 'max:20000']]);
            if ($data['status'] === 'completed') {
                $result = $this->plans->complete($worker, $id, $data['summary'] ?? null);

                return response()->json(['data' => ['id' => $id, 'type' => $job->type, 'status' => WorkerJob::COMPLETED, 'result' => $result]]);
            }
            $this->plans->fail($worker, $id, $data['error_code'], $data['error_message'] ?? null);
        } elseif ($job->type === WorkerJob::TYPE_TAG) {
            $data = $request->validate([...$base, 'items' => ['nullable', 'array', 'max:'.TagJob::MAX_PRODUCTS]]);
            if ($data['status'] === 'completed') {
                $result = $this->tags->complete($worker, $id, $data['items'] ?? []);

                return response()->json(['data' => ['id' => $id, 'type' => $job->type, 'status' => WorkerJob::COMPLETED, 'result' => $result]]);
            }
            $this->tags->fail($worker, $id, $data['error_code'], $data['error_message'] ?? null);
        } elseif ($job->type === WorkerJob::TYPE_TOPIC) {
            $data = $request->validate([...$base,
                'topics' => ['nullable', 'array', 'max:'.(TopicJob::COUNT_MAX * 2)],
            ]);
            if ($data['status'] === 'completed') {
                $result = $this->topics->complete($worker, $id, $data['topics'] ?? []);

                return response()->json(['data' => ['id' => $id, 'type' => $job->type, 'status' => WorkerJob::COMPLETED, 'result' => $result]]);
            }
            $this->topics->fail($worker, $id, $data['error_code'], $data['error_message'] ?? null);
        } elseif ($job->type === WorkerJob::TYPE_CONTENT) {
            $data = $request->validate([...$base,
                'files' => ['required_if:status,completed', 'array', 'max:20'],
                'files.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            ]);
            $data['status'] === 'completed'
                ? $this->content->complete($worker, $id, $request->file('files', []))
                : $this->content->fail($worker, $id, $data['error_code'], $data['error_message'] ?? null);
        } elseif ($job->type === WorkerJob::TYPE_PUBLISH) {
            $data = $request->validate([...$base, 'post_url' => ['nullable', 'string', 'max:500', 'url:http,https']]);
            $publishJob = SocialPublishJob::query()->findOrFail($id);
            $data['status'] === 'completed'
                ? $this->publish->complete($worker, $publishJob, $data['post_url'] ?? null)
                : $this->publish->fail($worker, $publishJob, $data['error_code'], $data['error_message'] ?? null);
        } else {
            $data = $request->validate([...$base,
                'image' => ['required_if:status,completed', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('ai90.worker.output_max_size_kb')],
                // Ảnh bìa lô phối đồ: hook bìa -> tiêu đề lô (ảnh outfit sau chọn outfit theo tiêu đề này)
                'title' => ['nullable', 'string', 'max:255'],
            ]);
            $imageJob = ImageJob::query()->findOrFail($id);
            if ($data['status'] === 'completed') {
                $this->images->complete($worker, $imageJob, $data['image']);
                $this->saveBatchTitle($imageJob->output()->firstOrFail(), $data['title'] ?? null);
            } else {
                $this->images->fail($worker, $imageJob, $data['error_code'], $data['error_message'] ?? null);
            }
        }

        return response()->json(['data' => ['id' => $id, 'type' => $job->type, 'status' => WorkerJob::query()->whereKey($id)->value('status')]]);
    }

    /**
     * GET /api/v1/worker-jobs/{id}/batch: lô của việc ảnh lô phối đồ worker đang / đã làm: id, title (hook bìa; null khi
     * bìa chưa xong / lỗi), position, total. Ảnh outfit đọc tiêu đề lô để chọn outfit đúng chủ đề.
     */
    public function batch(Request $request, int $id): JsonResponse
    {
        $job = ImageJob::query()->whereKey($id)->where('worker_id', $this->worker($request)->id)->firstOrFail();
        $image = ImageBatchImage::query()->where('output_id', $job->generation_output_id)->firstOrFail();

        return response()->json(['data' => $this->batchInfo($image)]);
    }

    /**
     * POST /api/v1/worker-jobs/{id}/extend: gia hạn việc đang làm (mọi loại; đăng bài không có hạn -> giữ nguyên).
     * 409 JOB_NOT_ASSIGNED: việc không còn của worker.
     */
    public function extend(Request $request, int $id): JsonResponse
    {
        $worker = $this->worker($request);
        $job = WorkerJob::query()->findOrFail($id);
        if (in_array($job->type, [WorkerJob::TYPE_IMAGE, WorkerJob::TYPE_OUTFIT], true)) {
            $job = $this->images->extend($worker, ImageJob::query()->findOrFail($id));
        } else {
            $job = DB::transaction(function () use ($worker, $id) {
                $locked = WorkerJob::query()->lockForUpdate()->findOrFail($id);
                if ($locked->status !== WorkerJob::PROCESSING || $locked->worker_id !== $worker->id || ! $worker->hasType($locked->type)) {
                    throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
                }
                $minutes = match ($locked->type) {
                    WorkerJob::TYPE_CONTENT => ContentJob::LEASE_MINUTES,
                    WorkerJob::TYPE_TOPIC => TopicJob::LEASE_MINUTES,
                    WorkerJob::TYPE_TAG => TagJob::LEASE_MINUTES,
                    WorkerJob::TYPE_PLAN => PlanJob::LEASE_MINUTES,
                    default => null,
                };
                if ($minutes) {
                    // Dựng nội dung tính hạn theo claimed_at (ContentJobService::claim)
                    $locked->update(['lease_expires_at' => now()->addMinutes($minutes), 'claimed_at' => now()]);
                }

                return $locked;
            });
        }

        return response()->json(['data' => ['id' => $job->id, 'lease_expires_at' => $job->lease_expires_at?->toIso8601String()]]);
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

    private function batchInfo(ImageBatchImage $image): array
    {
        $batch = ImageBatch::query()->find($image->image_batch_id, ['id', 'title', 'hook_locked', 'outfit_tags', 'outfit_labels', 'outfit_mode', 'text_template_id']);
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
            // 'product' (bài "Outfit - theo sản phẩm", 2026-11-24): chọn outfit OOTD cùng loại giày với product.tags.ootd, vẽ
            // bằng Google Flow (ảnh sản phẩm + ảnh gốc outfit), giày đúng theo ảnh sản phẩm; null = lô thường (ChatGPT)
            'outfit_mode' => $batch?->outfit_mode,
            // Worker phối đồ v2 in hook (title) lên ảnh bìa theo style này (style như ChannelTextTemplate); null = lô
            // không có template chữ
            'text_template' => $template ? ['id' => $template->id, 'name' => $template->name, 'style' => $template->style] : null,
            'position' => $image->position,
            'total' => ImageBatchImage::query()->where('image_batch_id', $image->image_batch_id)->count(),
        ];
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
                // Tag AI (ProductTags): ai_label + tags.ootd = hashtag giày dạng OOTD để chọn outfit cùng loại giày
                'ai_label' => $product->ai_label,
                'tags' => $product->tags,
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
