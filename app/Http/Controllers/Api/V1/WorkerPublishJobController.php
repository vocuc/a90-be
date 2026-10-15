<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TikTokPublishJob;
use App\Models\Worker;
use App\Services\TikTok\TikTokPublishJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API cho worker đăng bài TikTok: nhận 1 job (= 1 lần bấm đăng), lưu nháp bài vào tài khoản TikTok worker đang giữ,
 * rồi trả kết quả. Worker chưa được gắn tài khoản TikTok -> 409 TIKTOK_ACCOUNT_NOT_ASSIGNED.
 */
class WorkerPublishJobController extends Controller
{
    public function __construct(private TikTokPublishJobService $jobs) {}

    public function next(Request $request): JsonResponse|Response
    {
        $job = $this->jobs->claim($this->worker($request));
        if (! $job) {
            return response()->noContent();
        }

        // Phải trả kết quả trong ai90.worker.publish_lease_minutes, quá hạn bài về chờ gửi (không giao lại).
        return response()->json(['data' => [
            'id' => $job->id,
            'type' => 'tiktok_publish',
            'request' => $this->jobs->request($job),
        ]]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $job = TikTokPublishJob::query()->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'error_message' => ['nullable', 'string', 'max:490'],
        ]);

        $worker = $this->worker($request);
        if ($data['status'] === 'completed') {
            $this->jobs->complete($worker, $job);
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
