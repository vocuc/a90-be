<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelTextTemplate;
use App\Models\SocialPost;
use App\Services\Channel\ChannelPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API cho lệnh /lap-ke-hoach (skill D:\FlowKit\.claude\skills\lap-ke-hoach), token Sanctum ability "channel-plan" của
 * admin, đọc / ghi kênh của mọi shop (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.11, 5.7, 6.2).
 */
class ChannelPlanApiController extends Controller
{
    public function __construct(private ChannelPlanService $plans) {}

    public function context(int $channel): JsonResponse
    {
        return response()->json(['data' => $this->plans->context($this->channel($channel))]);
    }

    public function persona(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate(['persona' => ['required', 'array'], 'persona.*' => ['nullable', 'string', 'max:'.Channel::PERSONA_FIELD_MAX]]);
        $model = $this->plans->savePersona($this->channel($channel), $data['persona']);

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
        $lineup = $this->plans->saveLineup($this->channel($channel), $data['items'], true);

        return response()->json(['data' => ['id' => $lineup->id, 'status' => $lineup->status, 'items' => $lineup->items]], 201);
    }

    public function textTemplates(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate([
            'templates' => ['required', 'array', 'min:1', 'max:'.ChannelTextTemplate::SUGGEST_COUNT],
            'templates.*.name' => ['required', 'string', 'max:100'],
            'templates.*.sample_text' => ['nullable', 'string', 'max:150'],
            ...ChannelTextTemplate::styleRules('templates.*.style'),
        ]);
        $saved = $this->plans->saveTemplates($this->channel($channel), $data['templates']);

        return response()->json(['data' => $saved->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'status' => $t->status])], 201);
    }

    /** Gửi bài cho kỳ đang giữ slot (plan.id trong context). Gửi bổ sung slot trống: gọi lại cùng API. */
    public function submitPlan(Request $request, int $channel, int $plan): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1']]);
        $model = $this->channel($channel);
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
        $request->validate(['images' => ['required', 'array', 'min:1'], 'sample_key' => ['required', 'string'], 'hook' => ['required', 'string']]);
        $model = SocialPost::query()->find($post) ?? throw new BusinessException('NOT_FOUND', 'Không tìm thấy bài đăng.', 404);
        $model = $this->plans->redo($model, $request->all());

        return response()->json(['data' => ['id' => $model->id, 'status' => $model->status]]);
    }

    private function channel(int $id): Channel
    {
        return Channel::query()->find($id) ?? throw new BusinessException('NOT_FOUND', 'Không tìm thấy kênh.', 404);
    }
}
