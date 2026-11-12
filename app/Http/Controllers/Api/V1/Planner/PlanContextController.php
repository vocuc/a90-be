<?php

namespace App\Http\Controllers\Api\V1\Planner;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\PlanContext;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Trang "Bối cảnh": chủ shop quy ước bối cảnh cố định cho ảnh của bài kế hoạch (planner chỉ đọc). */
class PlanContextController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $contexts = PlanContext::query()->where('user_id', $request->user()->id)->with('model')->orderBy('id')->get();

        return response()->json(['data' => $contexts->map(fn (PlanContext $c) => self::toArray($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $context = PlanContext::query()->create(['user_id' => $request->user()->id, ...$data]);
        $this->audit->log('plan_context.create', $context, [], $request->user()->id);

        return response()->json(['data' => self::toArray($context->load('model'))], 201);
    }

    public function update(Request $request, int $context): JsonResponse
    {
        $context = $this->find($request, $context);
        $context->update($this->validated($request, $context));
        $this->audit->log('plan_context.update', $context, [], $request->user()->id);

        return response()->json(['data' => self::toArray($context->load('model'))]);
    }

    /** Xoá bối cảnh: bài kế hoạch cũ giữ ảnh, chỉ không "Làm lại" được nữa. */
    public function destroy(Request $request, int $context): Response
    {
        $context = $this->find($request, $context);
        $context->delete();
        $this->audit->log('plan_context.delete', $context, [], $request->user()->id);

        return response()->noContent();
    }

    private function find(Request $request, int $id): PlanContext
    {
        return PlanContext::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function validated(Request $request, ?PlanContext $current = null): array
    {
        $data = $request->validate([
            'name' => [$current ? 'sometimes' : 'required', 'string', 'max:'.PlanContext::NAME_MAX],
            'description' => [$current ? 'sometimes' : 'required', 'string', 'max:'.PlanContext::DESCRIPTION_MAX],
            'model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')->where('is_active', true)],
            'aspect_ratio' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $modelId = $data['model_id'] ?? $current?->model_id ?? AiModel::query()->where('is_active', true)->orderBy('id')->value('id');
        $model = AiModel::query()->find($modelId) ?? throw ValidationException::withMessages(['model_id' => 'Chưa có model AI nào đang bật.']);
        $ratio = $data['aspect_ratio'] ?? $current?->aspect_ratio ?? '9:16';
        if (! in_array($ratio, $model->supported_aspect_ratios ?? [], true)) {
            throw ValidationException::withMessages(['aspect_ratio' => "Model {$model->name} không hỗ trợ tỉ lệ {$ratio}."]);
        }

        return array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'model_id' => $model->id,
            'aspect_ratio' => $ratio,
            'is_active' => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null);
    }

    public static function toArray(PlanContext $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'aspect_ratio' => $c->aspect_ratio,
            'model' => $c->model ? ['id' => $c->model->id, 'name' => $c->model->name] : null,
            'is_active' => $c->is_active,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }
}
