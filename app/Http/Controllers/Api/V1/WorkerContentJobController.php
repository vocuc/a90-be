<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TikTokContentJob;
use App\Models\Worker;
use App\Services\TikTok\TikTokContentJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API cho worker viết nội dung bài TikTok (tiêu đề, nội dung, hashtag): nhận 1 job (= 1 bài) và trả kết quả.
 * Tách khỏi /api/v1/jobs (job ảnh) để worker ảnh không bị ảnh hưởng.
 */
class WorkerContentJobController extends Controller
{
    public function __construct(private TikTokContentJobService $jobs) {}

    public function next(Request $request): JsonResponse|Response
    {
        $job = $this->jobs->claim($this->worker($request));
        if (! $job) {
            return response()->noContent();
        }

        // Phải trả kết quả trong ai90.worker.content_lease_minutes, quá hạn job được giao lại.
        return response()->json(['data' => [
            'id' => $job->id,
            'request' => $this->jobs->request($job),
        ]]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $job = TikTokContentJob::query()->findOrFail($id);
        // Nội dung dài / hashtag sai quy tắc được cắt / lọc lại khi lưu, không trả lỗi cho worker.
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'title' => ['required_if:status,completed', 'nullable', 'string', 'max:1000'],
            'content' => ['nullable', 'string', 'max:20000'],
            'hashtags' => ['nullable', 'array', 'max:50'],
            'hashtags.*' => ['string', 'max:100'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'error_message' => ['nullable', 'string', 'max:490'],
        ]);

        $worker = $this->worker($request);
        if ($data['status'] === 'completed') {
            $this->jobs->complete($worker, $job, $data['title'], $data['content'] ?? null, $data['hashtags'] ?? []);
        } else {
            $this->jobs->fail($worker, $job, $data['error_code'], $data['error_message'] ?? null);
        }

        return response()->json(['data' => ['id' => $job->id, 'status' => $job->fresh()->status]]);
    }

    private function worker(Request $request): Worker
    {
        return $request->attributes->get('worker');
    }
}
