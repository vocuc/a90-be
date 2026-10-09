<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GenerationOutput;
use App\Models\Worker;
use App\Services\MediaStorage;
use App\Services\WorkerJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        $generation = $output->generation()->with(['model', 'creative.config'])->firstOrFail();

        return response()->json(['data' => [
            'id' => $output->id,
            'prompt' => $generation->prompt_snapshot,
            'aspect_ratio' => $generation->aspect_ratio,
            'input_image_url' => $media->url($generation->input_image_path),
            'input_mime' => $media->mimeType($generation->input_image_path),
            'model' => $generation->model->code,
            'extra_params' => (object) ($generation->creative->config?->extra_params ?? []),
            'attempt' => $output->attempts,
            'lease_expires_at' => $output->lease_expires_at,
        ]]);
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
