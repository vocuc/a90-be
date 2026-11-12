<?php

namespace App\Http\Controllers\Api\V1\Planner;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\SocialPost;
use App\Services\Social\PlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * API cho skill kế hoạch nội dung (FlowKit a51-skills/ke-hoach-noi-dung/thiet-ke.txt mục 9.5).
 * Gọi bằng "token kế hoạch" (ability planner) hoặc token đăng nhập.
 */
class PlannerController extends Controller
{
    public const MAX_DAYS = 31;

    public function __construct(private PlannerService $planner) {}

    /** GET /planner/context?from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function context(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        // Ngày theo giờ Việt Nam (ai90.social.planner_timezone), không theo UTC của app
        $tz = config('ai90.social.planner_timezone');
        $from = Carbon::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $data['to'], $tz)->startOfDay();
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            return response()->json(['message' => 'Tối đa '.self::MAX_DAYS.' ngày mỗi lần.', 'errors' => ['to' => ['Tối đa '.self::MAX_DAYS.' ngày mỗi lần.']]], 422);
        }

        return response()->json(['data' => $this->planner->context($request->user(), $from, $to)]);
    }

    /** POST /planner/posts: tạo 1 bài kế hoạch (bài ảnh). 201 khi tạo mới, 200 khi gửi lại cùng idempotency_key. */
    public function storePost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:80'],
            'type' => ['nullable', 'in:'.SocialPost::TYPE_PHOTO],
            'product_id' => ['required', 'integer'],
            'context_id' => ['required', 'integer'],
            'scheduled_at' => ['required', 'date'],
            'script' => ['required', 'array'],
            'script.y_chinh' => ['required', 'string', 'max:'.PlannerService::IDEA_MAX],
            'script.items' => ['required', 'array', 'min:'.PlannerService::MIN_ITEMS, 'max:'.PlannerService::MAX_ITEMS],
            'script.items.*' => ['required', 'string', 'max:'.PlannerService::ITEM_MAX],
        ], [
            'script.items.max' => 'Tối đa '.PlannerService::MAX_ITEMS.' ảnh mỗi bài.',
        ]);
        $data['scheduled_at'] = Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone'));

        $post = $this->planner->createPost($request->user(), $data);
        $post->load(SocialPostResource::RELATIONS);

        return (new SocialPostResource($post))->response()->setStatusCode($post->wasRecentlyCreated ? 201 : 200);
    }
}
