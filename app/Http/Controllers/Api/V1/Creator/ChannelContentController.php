<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelLineup;
use App\Models\ChannelTextTemplate;
use App\Models\ChannelTopic;
use App\Models\ContentPlan;
use App\Models\ContentPlanItem;
use App\Models\GenerationOutput;
use App\Models\PlanJob;
use App\Models\SocialPost;
use App\Models\TopicJob;
use App\Services\Channel\ChannelPlanService;
use App\Services\Channel\ChannelTopicService;
use App\Services\Channel\PlanJobService;
use App\Services\Channel\TopicJobService;
use App\Services\MediaStorage;
use App\Support\PostSamples;
use App\Support\ProductTags;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {}

    // ---- Lập kế hoạch mới bằng worker (a90-fe note/xay-kenh-tiktok/worker-lap-ke-hoach.txt) ----

    /** -> { plan_worker (shop có worker điều phối lập kế hoạch), job (lượt gần nhất | null), note_max } */
    public function planJob(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);

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
        $data = $request->validate([
            'sample_key' => ['required', 'string'],
            'hook' => ['required', 'string', 'max:200'],
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
            'tag_options' => ProductTags::options(),
            'topic_worker' => TopicJobService::hasWorker($channel->user_id),
            'job' => $this->topicJob($this->topicJobs->latest($channel)),
            'count' => ['min' => TopicJob::COUNT_MIN, 'max' => TopicJob::COUNT_MAX, 'default' => TopicJob::COUNT_DEFAULT],
        ]]);
    }

    /** Xây dựng chủ đề THỦ CÔNG: { name, tags? } -> 201 { data: Topic } (nguồn owner, bật sẵn). */
    public function storeTopic(Request $request, int $channel): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.ChannelTopic::NAME_MAX],
            'tags' => ['nullable', 'array'],
        ]);
        $name = ChannelTopic::cleanName($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Tên chủ đề không được trống.']);
        }
        if ($channel->topics()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'Kênh đã có chủ đề tên này.']);
        }
        [$tags, $errors] = ProductTags::cleanFilter($data['tags'] ?? []);
        if ($errors) {
            throw ValidationException::withMessages(['tags' => $errors]);
        }
        $topic = $channel->topics()->create(['name' => $name, 'tags' => $tags ?: null, 'source' => ProductTags::SOURCE_OWNER, 'status' => ChannelTopic::ACTIVE]);

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

    /** { name?, tags?: {kieu: [], got: [], ...}, status?: active|paused } Chủ shop sửa tên / tags -> nguồn owner. */
    public function updateTopic(Request $request, int $channel, int $topic): JsonResponse
    {
        $channel = $this->channel($request, $channel);
        $model = $channel->topics()->findOrFail($topic);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.ChannelTopic::NAME_MAX],
            'tags' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'required', 'in:'.ChannelTopic::ACTIVE.','.ChannelTopic::PAUSED],
        ]);
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
        if (array_key_exists('tags', $data)) {
            [$tags, $errors] = ProductTags::cleanFilter($data['tags'] ?? []);
            if ($errors) {
                throw ValidationException::withMessages(['tags' => $errors]);
            }
            $changes['tags'] = $tags ?: null;
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
        return [...$this->planSummary($p), 'items' => $p->items()->with('post')->get()->map(fn ($i) => $this->item($i))];
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
