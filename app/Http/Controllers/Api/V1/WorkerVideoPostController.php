<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialPublishJob;
use App\Models\Worker;
use App\Services\Social\SocialPublishJobService;
use App\Services\Social\VideoPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API cho worker dựng + đăng bài video có lịch (note/lap-lich-worker.txt ở repo frontend): nhận 1 bài đã tới giờ của
 * worker, dựng video theo template từ ảnh của bài, đăng bằng tài khoản đăng bài worker giữ, rồi báo kết quả.
 * Worker chưa giữ tài khoản đăng bài -> 409 ACCOUNT_NOT_ASSIGNED. Job không hết hạn.
 */
class WorkerVideoPostController extends Controller
{
    public function __construct(
        private VideoPostService $videos,
        private SocialPublishJobService $jobs,
    ) {}

    public function next(Request $request): JsonResponse|Response
    {
        $job = $this->videos->claim($this->worker($request));
        if (! $job) {
            return response()->noContent();
        }

        // request: như job đăng bài ảnh (tiêu đề, nội dung, hashtag, description, images, account) + template, product
        return response()->json(['data' => [
            'id' => $job->id,
            'type' => $job->platform->value.'_video_publish',
            'request' => [...$this->jobs->request($job), ...$this->videos->videoRequest($job)],
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
