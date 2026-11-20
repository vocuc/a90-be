<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialPublishJob;
use App\Models\Worker;
use App\Services\Channel\TextOverlayService;
use App\Services\Social\SocialPublishJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API chung cho worker đăng bài (note/gop-luong-dang-bai.txt ở repo frontend): nhận 1 bài (ảnh hoặc video) đã tới giờ
 * được giao cho worker, đăng lên mọi nền tảng trong job (bài video: dựng video theo template trước), rồi báo kết quả 1 lần.
 * Worker tự lo đăng bằng tài khoản nào. Job không hết hạn.
 *
 * Kênh (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 5.11): worker đăng bài của kênh còn nhận job CHÈN CHỮ
 * (type overlay), ưu tiên trước bài đăng, không cần tới giờ.
 *
 * GET  /api/v1/post-jobs       204: không có việc. 200 { data: { id, type: overlay|photo|video, request } }
 * POST /api/v1/post-jobs/{id}  { status: completed|failed, post_url?, error_code?, error_message? }
 *      409 JOB_NOT_ASSIGNED: chủ shop đã bấm "Tạo lại" (job bị bỏ), worker không cần làm gì thêm.
 * POST /api/v1/post-jobs/overlay/{id}  multipart: status=completed + files[image_id] (ảnh đã chèn chữ)
 *      | status=failed + error_code + error_message. 409 JOB_NOT_ASSIGNED: chủ shop đã sửa chữ (job bị thay).
 */
class WorkerPostJobController extends Controller
{
    public function __construct(private SocialPublishJobService $jobs, private TextOverlayService $overlays) {}

    public function next(Request $request): JsonResponse|Response
    {
        $worker = $this->worker($request);
        $worker->assertType(Worker::TYPE_PUBLISH);

        // Chèn chữ trước: bài kế hoạch phải chèn xong mới vào hàng chờ đăng
        $overlay = $this->overlays->claim($worker);
        if ($overlay) {
            return response()->json(['data' => [
                'id' => $overlay->id,
                'type' => 'overlay',
                'request' => $this->overlays->request($overlay),
            ]]);
        }

        $job = $this->jobs->claim($worker);
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

    public function submitOverlay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'files' => ['required_if:status,completed', 'array'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'error_message' => ['nullable', 'string', 'max:490'],
        ]);

        $worker = $this->worker($request);
        if ($data['status'] === 'completed') {
            $this->overlays->complete($worker, $id, $request->file('files', []));
        } else {
            $this->overlays->fail($worker, $id, $data['error_code'], $data['error_message'] ?? null);
        }

        return response()->json(['data' => ['id' => $id, 'status' => $data['status']]]);
    }

    private function worker(Request $request): Worker
    {
        return $request->attributes->get('worker');
    }
}
