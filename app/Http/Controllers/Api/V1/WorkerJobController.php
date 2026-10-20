<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Worker;
use App\Services\MediaStorage;
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

        // id: mã job để gửi kết quả (POST /jobs/{id}); request: dữ liệu gửi tới model.
        // logo: worker chèn vào ảnh trước khi gửi kết quả. Để ngoài request vì worker dán nguyên request cho model.
        // Phải trả kết quả trong ai90.worker.lease_minutes, quá hạn job được giao cho worker khác.
        return response()->json(['data' => [
            'id' => $output->id,
            'request' => $this->request($generation, $media),
            'logo' => $this->logo($generation, $media),
        ]]);
    }

    /** Logo = ảnh đại diện HIỆN TẠI của shop chủ generation; null khi không chèn hoặc shop không còn ảnh đại diện. */
    private function logo(Generation $generation, MediaStorage $media): ?array
    {
        $path = $generation->insert_logo ? $generation->user?->creatorProfile?->avatar_path : null;

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
        ]);

        $worker = $this->worker($request);
        if ($data['status'] === 'completed') {
            $this->jobs->complete($worker, $output, $data['image']);
        } else {
            $this->jobs->fail($worker, $output, $data['error_code'], $data['error_message'] ?? null);
        }

        return response()->json(['data' => ['id' => $output->id, 'status' => $output->fresh()->status]]);
    }

    private function worker(Request $request): Worker
    {
        return $request->attributes->get('worker');
    }
}
