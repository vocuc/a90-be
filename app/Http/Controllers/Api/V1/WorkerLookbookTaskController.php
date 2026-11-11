<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hashtag;
use App\Models\LookbookTask;
use App\Models\ProductImage;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Worker;
use App\Services\LookbookTaskService;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * API cho skill tạo lookbook (FlowKit a51-skills/tao-anh-look-book/thiet-ke.txt mục 3). Worker loại đăng bài, gắn tài
 * khoản shop (gắn nhiều shop thì gửi user_id ở next).
 * - GET  next?image_field=anh_san_pham[&user_id=]: sản phẩm tiếp theo (của shop hoặc được chia sẻ cho shop) + outfit
 *   shop đã dùng với sản phẩm đó; không còn sản phẩm -> 204. product.shared = true: sản phẩm của shop khác chia sẻ.
 * - POST {id}/complete (multipart): images[], title, content?, hashtags[]?, outfit_ids[]? -> bài chờ duyệt (source lookbook).
 * - POST {id}/fail: code, message? -> task lỗi (không tính gì thêm, lần next sau A51 giao sản phẩm khác).
 * Task của worker khác -> 404. Task đã kết thúc -> 409 LOOKBOOK_TASK_FINISHED (gửi lặp complete / fail thì trả kết quả cũ).
 */
class WorkerLookbookTaskController extends Controller
{
    /** File ảnh lookbook tối đa (KB). Ảnh ChatGPT thường 2-4 MB. */
    public const MAX_IMAGE_KB = 20480;

    public function __construct(private LookbookTaskService $tasks, private MediaStorage $media) {}

    public function next(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'image_field' => ['required', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
        ]);
        $worker = $this->worker($request);
        $user = $this->account($worker, $data['user_id'] ?? null);

        $task = $this->tasks->next($worker, $user, $data['image_field']);
        if (! $task) {
            return response()->noContent();
        }

        $product = $task->product;
        $image = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('field_key', $task->image_field)
            ->firstOrFail();

        return response()->json(['data' => [
            'task_id' => $task->id,
            'account' => ['id' => $user->id, 'name' => $user->name],
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'prompt_note' => $product->prompt_note,
                'shared' => ! $product->isOwnedBy($user),
                'image' => ['field_key' => $image->field_key, 'url' => $this->media->url($image->path)],
            ],
            'used_outfit_ids' => $this->tasks->usedOutfitIds($product->id, $user->id),
        ]]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $task = $this->task($request, $id);
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.SocialPost::MAX_IMAGES],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KB],
            'title' => ['required', 'string', 'max:'.SocialPost::TITLE_MAX],
            'content' => ['nullable', 'string', 'max:'.SocialPost::CONTENT_MAX],
            'hashtags' => ['nullable', 'array', 'max:'.SocialPost::MAX_HASHTAGS],
            'hashtags.*' => ['string', 'max:50', 'regex:'.Hashtag::PATTERN],
            'outfit_ids' => ['nullable', 'array'],
            'outfit_ids.*' => ['integer', 'min:1'],
        ], ['hashtags.max' => 'Tối đa '.SocialPost::MAX_HASHTAGS.' hashtag.']);

        $post = $this->tasks->complete($task, $request->file('images'), $data);

        return response()->json(['data' => [
            'task_id' => $task->id,
            'status' => LookbookTask::STATUS_COMPLETED,
            'post' => ['id' => $post->id, 'status' => $post->status, 'source' => $post->source],
        ]]);
    }

    public function fail(Request $request, int $id): JsonResponse
    {
        $task = $this->task($request, $id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $task = $this->tasks->fail($task, $data['code'], $data['message'] ?? null);

        return response()->json(['data' => ['task_id' => $task->id, 'status' => $task->status, 'error_code' => $task->error_code]]);
    }

    private function worker(Request $request): Worker
    {
        /** @var Worker $worker */
        $worker = $request->attributes->get('worker');
        $worker->assertType(Worker::TYPE_PUBLISH);

        return $worker;
    }

    /** Tài khoản shop làm lookbook: worker gắn 1 tài khoản thì lấy luôn, gắn nhiều thì phải gửi user_id. */
    private function account(Worker $worker, ?int $userId): User
    {
        $users = $worker->users()->get();
        if ($users->isEmpty()) {
            throw new BusinessException('WORKER_ACCOUNT_NOT_ASSIGNED', 'Worker chưa được gắn tài khoản A51.', 409);
        }
        if ($userId !== null) {
            return $users->firstWhere('id', $userId)
                ?? throw ValidationException::withMessages(['user_id' => 'Worker không được gắn tài khoản này.']);
        }
        if ($users->count() > 1) {
            throw ValidationException::withMessages(['user_id' => 'Worker gắn nhiều tài khoản: gửi user_id của shop làm lookbook.']);
        }

        return $users->first();
    }

    private function task(Request $request, int $id): LookbookTask
    {
        $worker = $this->worker($request);

        return LookbookTask::query()->whereKey($id)->where('worker_id', $worker->id)->firstOrFail();
    }
}
