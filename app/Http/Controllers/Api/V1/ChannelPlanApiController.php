<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\EnsureChannelPlanToken;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelTextTemplate;
use App\Models\ChannelTopic;
use App\Models\ContentPlanItem;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Channel\ChannelPlanService;
use App\Services\Channel\ChannelTopicService;
use App\Services\Channel\ProductTagService;
use App\Support\ProductTags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API cho lệnh /lap-ke-hoach (skill D:\FlowKit\.claude\skills\lap-ke-hoach), token Sanctum ability "channel-plan" của
 * admin, đọc / ghi kênh của mọi shop (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.11, 5.7, 6.2).
 */
class ChannelPlanApiController extends Controller
{
    public function __construct(private ChannelPlanService $plans, private ProductTagService $productTags, private ChannelTopicService $topics) {}

    /** ?fill_plan=<id kỳ>: chế độ TẠO BỔ SUNG, plan = kỳ đó với các slot còn thiếu bài. ?redo_only=1: chế độ LÀM LẠI (plan
     * null, không giữ slot kỳ mới). */
    public function context(Request $request, int $channel): JsonResponse
    {
        $model = $this->channel($request, $channel);
        $fill = $request->filled('fill_plan')
            ? ($model->plans()->find((int) $request->query('fill_plan')) ?? throw new BusinessException('NOT_FOUND', 'Không tìm thấy kỳ kế hoạch.', 404))
            : null;

        return response()->json(['data' => $this->plans->context($model, $fill, $request->boolean('redo_only'))]);
    }

    public function persona(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate(['persona' => ['required', 'array'], 'persona.*' => ['nullable', 'string', 'max:'.Channel::PERSONA_FIELD_MAX]]);
        $model = $this->plans->savePersona($this->channel($request, $channel), $data['persona']);

        return response()->json(['data' => ['persona' => $model->persona]]);
    }

    public function lineup(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sample_key' => ['required', 'string'],
            'items.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.reason' => ['nullable', 'string'],
        ]);
        $lineup = $this->plans->saveLineup($this->channel($request, $channel), $data['items'], true);

        return response()->json(['data' => ['id' => $lineup->id, 'status' => $lineup->status, 'items' => $lineup->items]], 201);
    }

    public function textTemplates(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate([
            'templates' => ['required', 'array', 'min:1', 'max:'.ChannelTextTemplate::SUGGEST_COUNT],
            'templates.*.name' => ['required', 'string', 'max:100'],
            'templates.*.sample_text' => ['nullable', 'string', 'max:150'],
            ...ChannelTextTemplate::sampleKeyRules('templates.*.sample_keys'),
            ...ChannelTextTemplate::styleRules('templates.*.style'),
        ]);
        $saved = $this->plans->saveTemplates($this->channel($request, $channel), $data['templates']);

        return response()->json(['data' => $saved->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'status' => $t->status,
            'sample_keys' => $t->sample_keys])], 201);
    }

    /**
     * Tạo chủ đề cho kênh theo yêu cầu người dùng (2026-11-24): { topics: [{name, category?, note?}] } (1-30) ->
     * 201 { data: { created: [{id, name, note, category}], skipped: [{name, reason}] } }. category = tên nhóm, chưa có thì tạo.
     */
    public function topics(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate([
            'topics' => ['required', 'array', 'min:1', 'max:30'],
            'topics.*.name' => ['required', 'string', 'max:'.ChannelTopic::NAME_MAX],
            'topics.*.category' => ['nullable', 'string', 'max:40'],
            'topics.*.note' => ['nullable', 'string', 'max:'.ChannelTopic::NOTE_MAX],
        ]);

        return response()->json(['data' => $this->topics->addTopics($this->channel($request, $channel), $data['topics'])], 201);
    }

    /** Gửi bài cho kỳ đang giữ slot (plan.id trong context). Gửi bổ sung slot trống: gọi lại cùng API. */
    public function submitPlan(Request $request, int $channel, int $plan): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1']]);
        $model = $this->channel($request, $channel);
        $planModel = $model->plans()->find($plan) ?? throw new BusinessException('NOT_FOUND', 'Không tìm thấy kỳ kế hoạch.', 404);
        $result = $this->plans->submitItems($model, $planModel, $data['items']);

        return response()->json(['data' => [
            'plan' => ['id' => $result['plan']->id, 'status' => $result['plan']->status],
            'accepted' => $result['accepted'],
            'rejected' => $result['rejected'],
            'warnings' => $result['warnings'],
        ]]);
    }

    /** Nội dung làm lại cho bài chủ shop đã bấm "Làm lại". */
    public function redo(Request $request, int $post): JsonResponse
    {
        // images: bài lô phối đồ (vd lookbook) không gửi ảnh mà gửi outfit { count, product_id }; bài lô ảnh sản phẩm
        // (unboxing) gửi product_batch { product_id }
        $model = SocialPost::query()->find($post);
        if (! $model || ! EnsureChannelPlanToken::allowsShop($request, (int) $model->user_id)) {
            throw new BusinessException('NOT_FOUND', 'Không tìm thấy bài đăng.', 404);
        }
        // Chế độ "Viết lại bằng AI" (redo_mode content, 2026-11-25): chỉ gửi hook / title / content / hashtags
        $contentOnly = $model->redo_mode === \App\Services\Channel\ChannelPlanService::REDO_CONTENT;
        $request->validate(['images' => ['nullable', 'array'], 'outfit' => ['nullable', 'array'], 'product_batch' => ['nullable', 'array'], 'product_id' => ['nullable', 'integer'],
            'sample_key' => [$contentOnly ? 'nullable' : 'required', 'string'], 'hook' => ['nullable', 'string']]);
        $model = $this->plans->redo($model, $request->all());

        return response()->json(['data' => ['id' => $model->id, 'status' => $model->status]]);
    }

    /** Nội dung làm lại cho bài trong kỳ CHƯA DUYỆT chủ shop bấm "Làm lại" (context item_redo_requests). */
    public function redoItem(Request $request, int $item): JsonResponse
    {
        $request->validate(['images' => ['nullable', 'array'], 'outfit' => ['nullable', 'array'], 'product_batch' => ['nullable', 'array'], 'product_id' => ['nullable', 'integer'], 'sample_key' => ['required', 'string'], 'hook' => ['nullable', 'string']]);
        $model = ContentPlanItem::query()->with('plan.channel:id,user_id')->find($item);
        if (! $model || ! EnsureChannelPlanToken::allowsShop($request, (int) $model->plan?->channel?->user_id)) {
            throw new BusinessException('NOT_FOUND', 'Không tìm thấy bài kế hoạch.', 404);
        }
        $model = $this->plans->redoItem($model, $request->all());

        return response()->json(['data' => ['id' => $model->id, 'plan_id' => $model->plan_id, 'hook' => $model->hook]]);
    }

    /**
     * Sản phẩm ĐÃ GẮN TAG lọc theo tag (chu-de-va-tag-san-pham.txt mục 5): ?kieu=&got=&mau=&phong_cach=&dip=&limit=
     * (mỗi tham số nhiều giá trị cách dấu phẩy = HOẶC, giữa các tham số = VÀ). Mỗi sản phẩm 1 ref ngẫu nhiên trong 10 ảnh
     * tạo gần nhất. -> { products: [{id, label, tags, used, last_used_at, ref}], total, untagged }
     */
    public function products(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:'.ProductTagService::LIST_MAX]]);
        [$filter, $errors] = ProductTags::cleanFilter($request->only(ProductTags::tagFields()));
        if ($errors) {
            throw ValidationException::withMessages(['tags' => $errors]);
        }

        return response()->json(['data' => $this->productTags->catalog($this->channel($request, $channel), $filter, (int) ($data['limit'] ?? 100))]);
    }

    /** Skill /gan-tag-san-pham: sản phẩm của shop cần gắn tag (?all=1: mọi sản phẩm có ảnh). */
    public function untaggedProducts(Request $request, int $user): JsonResponse
    {
        $data = $request->validate(['all' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->productTags->untagged($this->shop($request, $user), (bool) ($data['all'] ?? false))]);
    }

    /** Skill /gan-tag-san-pham gửi tag: { items: [{ id, image_id, ai_label, tags: {kieu, got, mau, phong_cach, dip} }] } */
    public function saveProductTags(Request $request, int $user): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1', 'max:'.ProductTagService::LIST_MAX]]);

        return response()->json(['data' => $this->productTags->saveAi($this->shop($request, $user), $data['items'])]);
    }

    /** Shop phải được admin gắn cho worker điều phối đang gọi; không thì 404 (như không tồn tại). */
    private function shop(Request $request, int $id): User
    {
        $user = EnsureChannelPlanToken::allowsShop($request, $id) ? User::query()->find($id) : null;

        return $user ?? throw new BusinessException('NOT_FOUND', 'Không tìm thấy shop.', 404);
    }

    /** Kênh của shop đã gắn cho worker điều phối đang gọi; không thì 404. */
    private function channel(Request $request, int $id): Channel
    {
        $channel = Channel::query()->find($id);
        if (! $channel || ! EnsureChannelPlanToken::allowsShop($request, (int) $channel->user_id)) {
            throw new BusinessException('NOT_FOUND', 'Không tìm thấy kênh.', 404);
        }

        return $channel;
    }
}
