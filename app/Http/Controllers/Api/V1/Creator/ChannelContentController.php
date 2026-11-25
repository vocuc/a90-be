<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelLineup;
use App\Models\ChannelTextTemplate;
use App\Models\ChannelTopic;
use App\Models\ChannelTopicCategory;
use App\Models\ContentPlan;
use App\Models\ContentPlanItem;
use App\Models\GenerationOutput;
use App\Models\PlanJob;
use App\Models\SocialPost;
use App\Models\TopicJob;
use App\Services\Channel\ChannelPlanService;
use App\Services\Channel\ChannelTopicService;
use App\Services\Channel\ChannelRescheduleService;
use App\Services\Channel\PlanJobService;
use App\Services\Channel\TopicJobService;
use App\Services\MediaStorage;
use App\Support\PostSamples;
use App\Support\ProductTags;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phần chủ shop xem / duyệt nội dung kênh trên A51 (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 6.1):
 * tuyến nội dung, template chữ, kỳ kế hoạch, lịch đăng, sửa chữ trên ảnh, đánh dấu làm lại.
 * Soạn nội dung do lệnh /lap-ke-hoach làm (ChannelPlanApiController), không có ở đây.
 */
class ChannelContentController extends Controller
{
    public function __construct(
        private ChannelPlanService $plans,
        private MediaStorage $media,
        private ChannelTopicService $topics,
        private TopicJobService $topicJobs,
        private PlanJobService $planJobs,
        private ChannelRescheduleService $reschedule,
    ) {}

    // ---- Lập kế hoạch mới bằng worker (a90-fe note/xay-kenh-tiktok/worker-lap-ke-hoach.txt) ----

    /** -> { plan_worker (shop có worker điều phối lập kế hoạch), job (lượt gần nhất | null), note_max } */
    public function planJob(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        // Việc làm lại còn chờ mà không còn bài nào cần làm lại (kỳ đã huỷ / xoá trước bản sửa 2026-11-25): dọn luôn
        $this->planJobs->dropIdleRedo($channel);

        return response()->json(['data' => [
            'plan_worker' => PlanJobService::hasWorker($channel->user_id),
            'job' => $this->planJobData($this->planJobs->latest($channel)),
            'note_max' => PlanJob::NOTE_MAX,
        ]]);
    }

    /**
     * Lập kế hoạch mới: { note? } -> 201 { data: job }. 409 PLAN_WORKER_REQUIRED | CHANNEL_NOT_READY | TOPICS_REQUIRED |
     * PLAN_DRAFT_PENDING (kỳ trước chờ duyệt) | PLAN_JOB_RUNNING.
     */
    public function startPlanJob(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:'.PlanJob::NOTE_MAX],
            // Có: TẠO BỔ SUNG cho kỳ này (worker soạn bài cho các slot còn thiếu)
            'plan_id' => ['nullable', 'integer'],
        ]);
        $fill = isset($data['plan_id']) ? $channel->plans()->findOrFail((int) $data['plan_id']) : null;

        return response()->json(['data' => $this->planJobData($this->planJobs->create($channel, $data['note'] ?? null, $fill))], 201);
    }

    private function planJobData(?PlanJob $job): ?array
    {
        return $job ? [
            'id' => $job->id,
            // queued | processing | completed | failed
            'status' => $job->status,
            'note' => $job->payload['note'] ?? null,
            // Lượt TẠO BỔ SUNG cho kỳ này (null = lập kỳ mới)
            'fill_plan_id' => $job->payload['plan_id'] ?? null,
            // Lượt LÀM LẠI bài (tự tạo khi bấm "Làm lại")
            'redo' => (bool) ($job->payload['redo'] ?? false),
            // { summary, plan_id, plan_status } khi xong
            'result' => $job->result,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at,
            'finished_at' => $job->finished_at,
        ] : null;
    }

    // ---- Tuyến nội dung ----

    public function lineups(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $current = $channel->approvedLineup();

        return response()->json(['data' => [
            'current' => $current ? $this->lineup($current) : null,
            'pending' => $channel->lineups()->where('status', ChannelLineup::PENDING)->latest('id')->get()->map(fn ($l) => $this->lineup($l)),
        ]]);
    }

    public function updateLineup(Request $request, int $channel, int $lineup): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.count(PostSamples::all())],
            'items.*.sample_key' => ['required', 'string'],
            'items.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.reason' => ['nullable', 'string', 'max:300'],
        ]);
        $model = $channel->lineups()->findOrFail($lineup);
        if ($model->status === ChannelLineup::REJECTED) {
            throw new BusinessException('LINEUP_DECIDED', 'Tuyến đã từ chối, không sửa được.', 409);
        }

        return response()->json(['data' => $this->lineup($this->plans->saveLineup($channel, $data['items'], false, $model))]);
    }

    public function decideLineup(Request $request, int $channel, int $lineup, string $decision): JsonResponse
    {
        $model = $this->channel($request, $channel)->lineups()->findOrFail($lineup);

        return response()->json(['data' => $this->lineup($this->plans->decideLineup($model, $decision === 'approve'))]);
    }

    // ---- Template chữ ----

    public function textTemplates(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);

        return response()->json([
            'data' => $channel->textTemplates()->where('status', '!=', ChannelTextTemplate::REJECTED)->orderBy('id')->get()
                ->map(fn ($t) => $this->template($t)),
            'meta' => [
                'fonts' => ChannelTextTemplate::FONTS,
                'positions' => ChannelTextTemplate::POSITIONS,
                'backgrounds' => ChannelTextTemplate::BACKGROUNDS,
                'aligns' => ChannelTextTemplate::ALIGNS,
                // Loại bài viết mẫu gắn được template (bài ảnh); in_lineup: có trong tuyến nội dung đã duyệt của kênh
                'post_samples' => $this->templateSampleOptions($channel),
                'preview_image' => $this->previewImage($channel),
            ],
        ]);
    }

    /** Chủ shop tự thêm template chữ: được duyệt luôn (do chính chủ shop tạo). */
    public function storeTextTemplate(Request $request, int $channel): JsonResponse
    {
        $channelModel = $this->channel($request, $channel);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sample_text' => ['nullable', 'string', 'max:150'],
            ...ChannelTextTemplate::sampleKeyRules('sample_keys'),
            ...ChannelTextTemplate::styleRules('style'),
        ], ['name.required' => 'Đặt tên cho template.']);
        $model = $channelModel->textTemplates()->create([
            'name' => trim($data['name']),
            'sample_text' => isset($data['sample_text']) ? trim($data['sample_text']) : null,
            'style' => ChannelTextTemplate::cleanStyle($data['style']),
            'sample_keys' => ChannelTextTemplate::cleanSampleKeys($data['sample_keys'] ?? []),
            'status' => ChannelTextTemplate::APPROVED,
        ]);

        return response()->json(['data' => $this->template($model)], 201);
    }

    public function updateTextTemplate(Request $request, int $channel, int $template): JsonResponse
    {
        $model = $this->channel($request, $channel)->textTemplates()->findOrFail($template);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'sample_text' => ['sometimes', 'nullable', 'string', 'max:150'],
            ...($request->has('sample_keys') ? ChannelTextTemplate::sampleKeyRules('sample_keys') : []),
            ...($request->has('style') ? ChannelTextTemplate::styleRules('style') : []),
        ]);
        $model->update([
            ...(isset($data['name']) ? ['name' => trim($data['name'])] : []),
            ...(array_key_exists('sample_text', $data) ? ['sample_text' => $data['sample_text']] : []),
            ...(isset($data['style']) ? ['style' => ChannelTextTemplate::cleanStyle($data['style'])] : []),
            ...($request->has('sample_keys') ? ['sample_keys' => ChannelTextTemplate::cleanSampleKeys($data['sample_keys'] ?? [])] : []),
        ]);

        return response()->json(['data' => $this->template($model)]);
    }

    public function decideTextTemplate(Request $request, int $channel, int $template, string $decision): JsonResponse
    {
        $channelModel = $this->channel($request, $channel);
        $model = $channelModel->textTemplates()->findOrFail($template);
        $moved = 0;
        if ($decision === 'approve') {
            $model->update(['status' => ChannelTextTemplate::APPROVED]);
        } else {
            // Bài đang dùng template này chuyển sang template đã duyệt khác + chèn chữ lại (không để bài mất chữ hook)
            $moved = $this->plans->retireTemplate($channelModel, $model);
        }

        return response()->json(['data' => [...$this->template($model->fresh()), 'moved_posts' => $moved]]);
    }

    // ---- Kỳ kế hoạch ----

    public function plans(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $plans = $channel->plans()->where('status', '!=', ContentPlan::PREPARING)->withCount([
            'items as total_items' => fn ($q) => $q->where('status', '!=', ContentPlanItem::CANCELLED),
        ])->latest('id')->limit(50)->get();

        return response()->json(['data' => $plans->map(fn (ContentPlan $p) => [...$this->planSummary($p), 'total_items' => $p->total_items,
            // Số slot còn thiếu bài (nút "Tạo bổ sung"), chỉ kỳ chờ duyệt / đã duyệt
            'missing_slots' => in_array($p->status, [ContentPlan::DRAFT, ContentPlan::APPROVED], true) ? count($this->plans->missingSlots($p)) : 0,
        ])]);
    }

    public function plan(Request $request, int $channel, int $plan): JsonResponse
    {
        $model = $this->channel($request, $channel)->plans()->findOrFail($plan);

        return response()->json(['data' => $this->planDetail($model)]);
    }

    public function decidePlan(Request $request, int $channel, int $plan, string $decision): JsonResponse
    {
        $model = $this->channel($request, $channel)->plans()->findOrFail($plan);
        $model = $decision === 'approve' ? $this->plans->approve($model) : $this->plans->cancel($model);

        return response()->json(['data' => $this->planDetail($model->fresh())]);
    }

    /**
     * Dời lịch giữ nguyên giờ đăng: không gửi item_ids = dời cả kỳ (date = ngày đầu kỳ mới); có item_ids = dời các bài đó
     * sang ngày date. 409 PLAN_CLOSED | ITEM_PUBLISHING | TIME_CONFLICT; 422 TIME_PASSED | SAME_DATE | ITEM_NOT_FOUND.
     */
    /** "Làm mới giờ đăng": đặt lại giờ các bài của kỳ theo khung giờ hiện tại của kênh (giữ ngày). */
    public function resetPlanTimes(Request $request, int $channel, int $plan): JsonResponse
    {
        $model = $this->channel($request, $channel)->plans()->findOrFail($plan);
        $result = $this->reschedule->redistribute($model);

        return response()->json(['data' => [...$this->planDetail($model->fresh()), ...$result]]);
    }

    public function reschedulePlan(Request $request, int $channel, int $plan): JsonResponse
    {
        $model = $this->channel($request, $channel)->plans()->findOrFail($plan);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'item_ids' => ['nullable', 'array', 'min:1', 'max:200'],
            'item_ids.*' => ['integer'],
        ]);
        $moved = $this->plans->reschedule($model, $data['date'], $data['item_ids'] ?? null);

        return response()->json(['data' => [...$this->planDetail($model->fresh()), 'moved' => $moved]]);
    }

    /** Xoá hẳn kỳ đã huỷ. 409 PLAN_NOT_CANCELLED. */
    public function deletePlan(Request $request, int $channel, int $plan): JsonResponse
    {
        $this->plans->delete($this->channel($request, $channel)->plans()->findOrFail($plan));

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function updateItem(Request $request, int $channel, int $plan, int $item): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $model = $channel->plans()->findOrFail($plan)->items()->findOrFail($item);
        // Chỉ cần gửi phần muốn đổi (vd chỉ hook / tiêu đề): phần không gửi giữ theo bài hiện tại
        $data = $request->validate([
            'sample_key' => ['sometimes', 'required', 'string'],
            'hook' => ['nullable', 'string', 'max:200'],
            'video_template_id' => ['nullable', 'integer'],
            'images' => ['nullable', 'array', 'max:'.SocialPost::MAX_IMAGES],
            'images.*.ref' => ['required', 'string'],
            'outfit' => ['nullable', 'array'],
            'outfit.count' => ['nullable', 'integer'],
            'outfit.product_id' => ['nullable', 'integer'],
            'outfit.tags' => ['nullable', 'array'],
            'outfit.tags.*' => ['string', 'max:50'],
            'outfit.labels' => ['nullable', 'array'],
            'outfit.labels.*' => ['nullable', 'string', 'max:100'],
            'product_batch' => ['nullable', 'array'],
            'product_batch.product_id' => ['nullable', 'integer'],
            'images.*.text' => ['nullable', 'string', 'max:150'],
            'text_template_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:'.SocialPost::TITLE_MAX],
            'content' => ['nullable', 'string', 'max:'.SocialPost::CONTENT_MAX],
            'hashtags' => ['nullable', 'array', 'max:'.SocialPost::MAX_HASHTAGS],
        ]);

        return response()->json(['data' => $this->item($this->plans->updateItem($channel, $model, $data)->fresh())]);
    }

    public function cancelItem(Request $request, int $channel, int $plan, int $item): JsonResponse
    {
        // Nút X: xoá hẳn bài khỏi kỳ (kèm bài đăng chưa được worker nhận). 409 ITEM_PUBLISHING
        $model = $this->channel($request, $channel)->plans()->findOrFail($plan)->items()->findOrFail($item);
        $this->plans->deleteItem($model);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Nút "Làm lại" bài chưa duyệt: đánh dấu (POST) / bỏ đánh dấu (DELETE). Đánh dấu xong giao worker điều phối soạn lại
     * ngay -> { data: item + { redo_queued } } (redo_queued false: shop chưa gắn worker điều phối).
     */
    public function redoItem(Request $request, int $channel, int $plan, int $item): JsonResponse
    {
        $model = $this->channel($request, $channel)->plans()->findOrFail($plan)->items()->findOrFail($item);
        $queued = $this->plans->requestItemRedo($model, $request->isMethod('post'));

        return response()->json(['data' => [...$this->item($model->fresh()), 'redo_queued' => $queued]]);
    }

    /**
     * Nút "AI viết lại cả kỳ": đánh dấu (POST) / bỏ đánh dấu (DELETE) mọi bài chưa duyệt của kỳ chờ duyệt.
     * -> { data: { items: số bài, redo_queued } }. 409 PLAN_NOT_DRAFT.
     */
    public function redoPlan(Request $request, int $channel, int $plan): JsonResponse
    {
        $model = $this->channel($request, $channel)->plans()->findOrFail($plan);
        [$count, $queued] = $this->plans->requestPlanRedo($model, $request->isMethod('post'));

        return response()->json(['data' => ['items' => $count, 'redo_queued' => $queued]]);
    }

    // ---- Lịch đăng ----

    public function calendar(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        if ($from->diffInDays($to) > 62) {
            $to = $from->copy()->addDays(62)->endOfDay();
        }
        $posts = $channel->posts()->with(['images.output', 'topic:id,name'])->whereBetween('scheduled_at', [$from, $to])
            ->orderBy('scheduled_at')->get();

        return response()->json(['data' => $posts->map(fn (SocialPost $p) => $this->calendarPost($p))]);
    }

    /** Sửa chữ trên ảnh / template chữ -> worker chèn lại. */
    public function updateOverlays(Request $request, int $post): JsonResponse
    {
        $model = $this->post($request, $post);
        $data = $request->validate([
            'overlays' => ['required', 'array', 'min:1', 'max:'.SocialPost::MAX_IMAGES],
            'overlays.*.image_id' => ['required', 'integer'],
            'overlays.*.text' => ['nullable', 'string', 'max:150'],
            'overlays.*.text_template_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->calendarPost($this->plans->updateOverlays($model, $data['overlays'])->load('images.output'))]);
    }

    /** Đánh dấu (POST) / bỏ đánh dấu (DELETE) làm lại: lần chạy /lap-ke-hoach kế tiếp làm lại bài. */
    /**
     * 1 bài của kênh (hook, chủ đề, dạng bài, chữ trên ảnh, cờ làm lại): khối "Nội dung kênh" ở trang /posts/:id
     * (tab Lịch đăng gộp vào /posts, 2026-11-25). Bài không thuộc kênh -> 404.
     */
    public function channelPost(Request $request, int $post): JsonResponse
    {
        $model = $this->post($request, $post);
        if (! $model->channel_id) {
            throw new BusinessException('POST_NO_CHANNEL', 'Bài không thuộc kênh nào.', 404);
        }

        return response()->json(['data' => [...$this->calendarPost($model->load(['images.output', 'topic:id,name'])),
            'channel' => ['id' => $model->channel_id, 'name' => $model->channel?->name]]]);
    }

    public function redo(Request $request, int $post): JsonResponse
    {
        $model = $this->plans->requestRedo($this->post($request, $post), $request->isMethod('post'));

        return response()->json(['data' => $this->calendarPost($model->load('images.output'))]);
    }

    // ---- Chủ đề kênh (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt mục 4) ----

    /**
     * Chủ đề kênh kèm số bài theo loại bài + lần dùng gần nhất.
     * -> { topics, tag_options, topic_worker (shop có worker lập chủ đề), job (lượt xây dựng tự động gần nhất | null),
     *      count: { min, max, default } }
     */
    public function topics(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);

        // Mỗi chủ đề kèm hooks: hook đã viết theo chủ đề (bài đăng + bài kế hoạch chờ duyệt), mới trước
        $hooks = $this->topics->hooksFor($channel);

        return response()->json(['data' => [
            'topics' => array_map(fn ($t) => [...$t, 'hooks' => $hooks[$t['id']] ?? []], $this->topics->listFor($channel)),
            // Nhóm chủ đề của kênh theo thứ tự (chủ shop quản lý): [{ id, name, position, topics }]; chủ đề category_id null = "Khác"
            'categories' => $this->topics->categoryList($channel),
            'topic_worker' => TopicJobService::hasWorker($channel->user_id),
            'job' => $this->topicJob($this->topicJobs->latest($channel)),
            'count' => ['min' => TopicJob::COUNT_MIN, 'max' => TopicJob::COUNT_MAX, 'default' => TopicJob::COUNT_DEFAULT],
        ]]);
    }

    /** Xây dựng chủ đề THỦ CÔNG: { name, category_id?, note? } -> 201 { data: Topic } (nguồn owner, bật sẵn; không gửi nhóm = đoán theo tên). */
    public function storeTopic(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.ChannelTopic::NAME_MAX],
            'category_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:'.ChannelTopic::NOTE_MAX],
        ], ['note.max' => 'Ghi chú tối đa '.ChannelTopic::NOTE_MAX.' ký tự.']);
        $name = ChannelTopic::cleanName($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Tên chủ đề không được trống.']);
        }
        if ($channel->topics()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'Kênh đã có chủ đề tên này.']);
        }
        $note = ChannelTopic::cleanNote($data['note'] ?? null);
        $categoryId = isset($data['category_id']) ? $this->categoryId($channel, $data['category_id'])
            : $this->topics->resolveCategory($channel, null, $name.' '.$note);
        $topic = $channel->topics()->create(['name' => $name, 'note' => $note, 'source' => ProductTags::SOURCE_OWNER, 'status' => ChannelTopic::ACTIVE,
            'category_id' => $categoryId]);

        return response()->json(['data' => collect($this->topics->listFor($channel))->firstWhere('id', $topic->id)], 201);
    }

    /**
     * Xây dựng chủ đề TỰ ĐỘNG: { count (1-30), note? } -> 201 { data: job } (việc cho worker lập chủ đề).
     * 409 TOPIC_WORKER_REQUIRED (shop chưa gắn worker lập chủ đề) | TOPIC_JOB_RUNNING (đang có lượt chưa xong).
     */
    public function startTopicJob(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:'.TopicJob::COUNT_MIN, 'max:'.TopicJob::COUNT_MAX],
            'note' => ['nullable', 'string', 'max:'.TopicJob::NOTE_MAX],
        ]);

        return response()->json(['data' => $this->topicJob($this->topicJobs->create($channel, (int) $data['count'], $data['note'] ?? null))], 201);
    }

    private function topicJob(?TopicJob $job): ?array
    {
        return $job ? [
            'id' => $job->id,
            // queued | processing | completed | failed
            'status' => $job->status,
            'count' => $job->payload['count'] ?? null,
            'note' => $job->payload['note'] ?? null,
            // { created, skipped, errors } khi xong
            'result' => $job->result,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at,
            'finished_at' => $job->finished_at,
        ] : null;
    }

    /**
     * Xoá chủ đề, kể cả chủ đề đã có bài (người dùng chốt 2026-11-23): bài đăng / bài kế hoạch của chủ đề giữ nguyên, chủ đề
     * thành "không xác định" (topic_id null, FK nullOnDelete). Không chặn worker lập chủ đề tạo lại chủ đề cùng tên. -> 204
     */
    public function destroyTopic(Request $request, int $channel, int $topic): Response
    {
        $this->channel($request, $channel)->topics()->findOrFail($topic)->delete();

        return response()->noContent();
    }

    /** { name?, category_id? (null = "Khác"), note?, status?: active|paused } Chủ shop sửa tên / nhóm / ghi chú -> nguồn owner. */
    public function updateTopic(Request $request, int $channel, int $topic): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $model = $channel->topics()->findOrFail($topic);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.ChannelTopic::NAME_MAX],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.ChannelTopic::NOTE_MAX],
            'status' => ['sometimes', 'required', 'in:'.ChannelTopic::ACTIVE.','.ChannelTopic::PAUSED],
        ], ['note.max' => 'Ghi chú tối đa '.ChannelTopic::NOTE_MAX.' ký tự.']);
        $changes = [];
        if (array_key_exists('name', $data)) {
            $name = ChannelTopic::cleanName($data['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Tên chủ đề không được trống.']);
            }
            if ($channel->topics()->where('name', $name)->whereKeyNot($model->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'Kênh đã có chủ đề tên này.']);
            }
            $changes['name'] = $name;
        }
        if (array_key_exists('note', $data)) {
            $changes['note'] = ChannelTopic::cleanNote($data['note']);
        }
        if (array_key_exists('category_id', $data)) {
            $changes['category_id'] = $data['category_id'] === null ? null : $this->categoryId($channel, $data['category_id']);
        }
        if ($changes) {
            $changes['source'] = ProductTags::SOURCE_OWNER;
        }
        if (isset($data['status'])) {
            $changes['status'] = $data['status'];
        }
        $model->update($changes);

        return response()->json(['data' => collect($this->topics->listFor($channel))->firstWhere('id', $model->id)]);
    }

    // ---- Nhóm chủ đề (chủ shop quản lý, 2026-11-24) ----

    /** { name } -> 201 { data: Category } (thêm cuối danh sách). */
    public function storeTopicCategory(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $name = $this->categoryName($channel, $request);
        if (count($this->topics->categories($channel)) >= ChannelTopicCategory::MAX) {
            throw ValidationException::withMessages(['name' => 'Tối đa '.ChannelTopicCategory::MAX.' nhóm chủ đề.']);
        }
        $category = $channel->topicCategories()->create(['name' => $name, 'position' => (int) $channel->topicCategories()->max('position') + 1]);

        return response()->json(['data' => collect($this->topics->categoryList($channel))->firstWhere('id', $category->id)], 201);
    }

    /** { name } đổi tên nhóm -> { data: Category }. */
    public function updateTopicCategory(Request $request, int $channel, int $category): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $model = $channel->topicCategories()->findOrFail($category);
        $model->update(['name' => $this->categoryName($channel, $request, $model->id)]);

        return response()->json(['data' => collect($this->topics->categoryList($channel))->firstWhere('id', $model->id)]);
    }

    /** { ids: [id...] } thứ tự nhóm mới (đủ mọi nhóm của kênh) -> { data: Category[] }. */
    public function orderTopicCategories(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        $own = $this->topics->categories($channel)->pluck('id')->sort()->values()->all();
        $sorted = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($sorted !== $own) {
            throw ValidationException::withMessages(['ids' => 'Danh sách nhóm không khớp, tải lại trang rồi thử lại.']);
        }
        DB::transaction(function () use ($channel, $ids) {
            foreach (array_values($ids) as $i => $id) {
                $channel->topicCategories()->whereKey((int) $id)->update(['position' => $i + 1]);
            }
        });

        return response()->json(['data' => $this->topics->categoryList($channel)]);
    }

    /** Xoá nhóm: chủ đề của nhóm về "Khác" (category_id null). -> 204 */
    public function destroyTopicCategory(Request $request, int $channel, int $category): Response
    {
        $this->channel($request, $channel)->topicCategories()->findOrFail($category)->delete();

        return response()->noContent();
    }

    private function categoryName(Channel $channel, Request $request, ?int $exceptId = null): string
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:'.ChannelTopicCategory::NAME_MAX]]);
        $name = ChannelTopicCategory::cleanName($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Tên nhóm không được trống.']);
        }
        if (mb_strtolower($name) === mb_strtolower(ChannelTopic::OTHER_CATEGORY)) {
            throw ValidationException::withMessages(['name' => '"'.ChannelTopic::OTHER_CATEGORY.'" là nhóm sẵn cho chủ đề chưa xếp nhóm, đặt tên khác.']);
        }
        if ($channel->topicCategories()->where('name', $name)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw ValidationException::withMessages(['name' => 'Kênh đã có nhóm tên này.']);
        }

        return $name;
    }

    /** Id nhóm thuộc kênh, không thì 422. */
    private function categoryId(Channel $channel, mixed $id): int
    {
        if (! $this->topics->categories($channel)->contains('id', (int) $id)) {
            throw ValidationException::withMessages(['category_id' => 'Nhóm chủ đề không thuộc kênh này.']);
        }

        return (int) $id;
    }

    // ---- Helpers ----

    private function channel(Request $request, int $id): Channel
    {
        return $request->user()->channels()->find($id) ?? throw new BusinessException('NOT_FOUND', 'Không tìm thấy kênh.', 404);
    }

    private function post(Request $request, int $id): SocialPost
    {
        return SocialPost::query()->where('user_id', $request->user()->id)->find($id)
            ?? throw new BusinessException('NOT_FOUND', 'Không tìm thấy bài đăng.', 404);
    }

    /** 1 ảnh sản phẩm của shop để xem trước template chữ. */
    private function previewImage(Channel $channel): ?string
    {
        $output = GenerationOutput::query()->usable()
            ->whereHas('generation', fn ($q) => $q->where('user_id', $channel->user_id))
            ->latest('id')->first();

        return $output ? $this->media->url($output->thumbPathOrOriginal()) : null;
    }

    private function lineup(ChannelLineup $lineup): array
    {
        return [
            'id' => $lineup->id,
            'status' => $lineup->status,
            'items' => collect($lineup->items)->map(fn ($i) => [...$i, 'name' => PostSamples::get($i['sample_key'])['name'] ?? $i['sample_key']])->all(),
            'approved_at' => $lineup->approved_at,
            'created_at' => $lineup->created_at,
        ];
    }

    private function template(ChannelTextTemplate $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'status' => $t->status, 'style' => $t->style,
            'sample_keys' => $t->sample_keys ?? [], 'sample_text' => $t->sample_text];
    }

    /** Loại bài viết mẫu (bài ảnh) chủ shop gắn được cho template chữ; bài trong tuyến đã duyệt xếp trước. */
    private function templateSampleOptions(Channel $channel): array
    {
        $lineup = $channel->approvedLineup()?->sampleKeys() ?? [];

        return collect(ChannelTextTemplate::sampleKeyOptions())
            ->map(fn ($key) => ['key' => $key, 'name' => PostSamples::get($key)['name'], 'in_lineup' => in_array($key, $lineup, true)])
            ->sortBy(fn ($o) => $o['in_lineup'] ? 0 : 1)->values()->all();
    }

    private function planSummary(ContentPlan $p): array
    {
        return [
            'id' => $p->id,
            'status' => $p->status,
            'start_date' => $p->start_date->toDateString(),
            'days' => $p->days,
            'slots' => count($p->slots ?? []),
            'warnings' => $p->warnings ?? [],
            'approved_at' => $p->approved_at,
            'created_at' => $p->created_at,
        ];
    }

    private function planDetail(ContentPlan $p): array
    {
        return [...$this->planSummary($p), 'items' => $p->items()->with('post.images.output')->get()->map(fn ($i) => $this->item($i))];
    }

    private function item(ContentPlanItem $i): array
    {
        $outputs = GenerationOutput::query()->whereIn('id', array_column($i->images ?? [], 'output_id'))->get()->keyBy('id');

        return [
            'id' => $i->id,
            'slot_index' => $i->slot_index,
            'scheduled_at' => $i->scheduled_at,
            'status' => $i->status,
            'sample_key' => $i->sample_key,
            'sample_name' => PostSamples::get($i->sample_key)['name'] ?? $i->sample_key,
            'hook' => $i->hook,
            // Bài không chữ (unboxing, video từ ảnh sản phẩm): hook = tiêu đề, chủ shop sửa tiêu đề
            'hook_from_title' => PostSamples::hookFromTitle(PostSamples::get($i->sample_key) ?? []),
            'redo_requested_at' => $i->redo_requested_at,
            'topic' => $i->topic_id ? ChannelTopic::query()->whereKey($i->topic_id)->value('name') : null,
            'format' => $i->format,
            'video_template_id' => $i->video_template_id,
            'text_template_id' => $i->text_template_id,
            // Bài lô phối đồ: ảnh tạo khi duyệt kỳ { count, product_id }
            'outfit' => $i->outfit,
            // Bài lô ảnh sản phẩm (unboxing): duyệt kỳ mới tạo lô tự động cho sản phẩm này
            'product_batch' => $i->product_batch ? [
                'product_id' => $i->product_batch['product_id'] ?? null,
                'product_name' => \App\Models\Product::query()->whereKey($i->product_batch['product_id'] ?? 0)->value('name'),
            ] : null,
            'images' => collect($i->images)->map(fn ($img) => [
                'ref' => ChannelPlanService::ref($img['generation_id'], $img['output_id']),
                'text' => $img['text'] ?? null,
                'thumb_url' => ($o = $outputs->get($img['output_id'])) ? $this->media->url($o->thumbPathOrOriginal()) : null,
            ])->all(),
            'title' => $i->title,
            'content' => (string) $i->content,
            'hashtags' => $i->hashtags ?? [],
            'social_post_id' => $i->social_post_id,
            'post_status' => $i->post?->status,
            // Ảnh THẬT của bài đăng sau khi duyệt (2026-11-25): bài lô phối đồ / lô ảnh sản phẩm không có ảnh lúc lập kỳ,
            // tạo xong ảnh thì tab Kế hoạch hiện ảnh này (bản đã chèn chữ nếu có) thay ô "Lô phối đồ" / "Lô ảnh"
            'post_images' => $i->post ? $i->post->images->sortBy('position')->values()
                ->filter(fn ($img) => $img->isUsable())
                ->map(fn ($img) => ['id' => $img->id, 'thumb_url' => $this->media->url($img->overlay_path ?: $img->thumbPath())])
                ->values()->all() : [],
        ];
    }

    private function calendarPost(SocialPost $p): array
    {
        return [
            'id' => $p->id,
            'type' => $p->type,
            'status' => $p->status,
            'scheduled_at' => $p->scheduled_at,
            'sample_key' => $p->sample_key,
            'sample_name' => $p->sample_key ? (PostSamples::get($p->sample_key)['name'] ?? $p->sample_key) : null,
            'hook' => $p->hook,
            'topic' => $p->topic?->name,
            'title' => $p->title,
            'hashtags' => $p->hashtags ?? [],
            'redo_requested_at' => $p->redo_requested_at,
            'last_error' => $p->last_error,
            'post_url' => $p->publishJobs()->whereNotNull('post_url')->latest('id')->value('post_url'),
            'images' => $p->images->sortBy('position')->values()->map(fn ($img) => [
                'id' => $img->id,
                'thumb_url' => $this->media->url($img->thumbPath()),
                'overlay_url' => $img->overlay_path ? $this->media->url($img->overlay_path) : null,
                'overlay_text' => $img->overlay_text,
                'overlay_status' => $img->overlay_status,
                'text_template_id' => $img->text_template_id,
            ])->all(),
        ];
    }
}
