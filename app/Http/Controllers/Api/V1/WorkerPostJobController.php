<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialPublishJob;
use App\Models\Worker;
use App\Services\Social\SocialPublishJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API chung cho worker đăng bài (note/gop-luong-dang-bai.txt ở repo frontend): nhận 1 bài (ảnh hoặc video) đã tới giờ
 * được giao cho worker, đăng lên mọi nền tảng trong job (bài video: dựng video theo template trước), rồi báo kết quả 1 lần.
 * Worker tự lo đăng bằng tài khoản nào. Job không hết hạn.
 *
 * GET  /api/v1/post-jobs       204: chưa có bài tới giờ. 200 { data: { id, type: photo|video, request } }
 * POST /api/v1/post-jobs/{id}  { status: completed|failed, post_url?, error_code?, error_message? }
 *      409 JOB_NOT_ASSIGNED: chủ shop đã bấm "Tạo lại" (job bị bỏ), worker không cần làm gì thêm.
 */
class WorkerPostJobController extends Controller
{
    public function __construct(private SocialPublishJobService $jobs) {}

    public function next(Request $request): JsonResponse|Response
    {
        $job = $this->jobs->claim($this->worker($request));
        if (! $job) {
            return response()->noContent();
        }

        $request = $this->jobs->request($job);

        return response()->json(['data' => [
            'id' => $job->id,
            'type' => $request['type'],
            'request' => $request,
        ]]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $job = SocialPublishJob::query()->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'error_message' => ['nullable', 'string', 'max:490'],
            'post_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
        ]);

        $worker = $this->worker($request);
        if ($data['status'] === 'completed') {
            $this->jobs->complete($worker, $job, $data['post_url'] ?? null);
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
