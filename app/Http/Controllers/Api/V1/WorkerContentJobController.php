<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\Channel\ContentJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Content-worker (a90-fe note/xay-kenh-tiktok/worker-content.txt): nhận NGUYÊN LIỆU bài ảnh, gửi lại ảnh hoàn chỉnh.
 *
 * GET  /api/v1/content-jobs        204: không có việc. 200 { data: { id, request: {post_id, sample_key, sample_name,
 *                                  cover_collage, hook, images: [{image_id, url, kind, product, text, text_template}]} } }
 * POST /api/v1/content-jobs/{id}   multipart: status=completed + files[0..n-1] (ảnh hoàn chỉnh theo thứ tự slide;
 *                                  cover_collage = true thì thêm bìa ở đầu), hoặc status=failed + error_code, error_message.
 */
class WorkerContentJobController extends Controller
{
    public function __construct(private ContentJobService $jobs) {}

    public function next(Request $request): JsonResponse|Response
    {
        $worker = $this->worker($request);
        $worker->assertType(Worker::TYPE_CONTENT);
        $job = $this->jobs->claim($worker);
        if (! $job) {
            return response()->noContent();
        }

        return response()->json(['data' => ['id' => $job->id, 'request' => $this->jobs->request($job)]]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $worker = $this->worker($request);
        $worker->assertType(Worker::TYPE_CONTENT);
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'files' => ['required_if:status,completed', 'array', 'max:20'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'error_message' => ['nullable', 'string', 'max:490'],
        ]);

        if ($data['status'] === 'completed') {
            $this->jobs->complete($worker, $id, $request->file('files', []));
        } else {
            $this->jobs->fail($worker, $id, $data['error_code'], $data['error_message'] ?? null);
        }

        return response()->json(['data' => ['id' => $id, 'status' => $data['status']]]);
    }

    private function worker(Request $request): Worker
    {
        return $request->attributes->get('worker');
    }
}
