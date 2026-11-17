<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromptTemplateResource;
use App\Models\AiCreativeConfig;
use App\Models\PromptTemplate;
use App\Services\CreativeTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Template prompt của Creator (note/template-prompt.txt ở repo frontend): các block { title, content } ghép thành prompt.
 * Creative chọn Template thì prompt cuối = prompt Template + prompt riêng của Creative.
 * Sửa Template -> gộp lại prompt của mọi Creative đang dùng. Template của người khác -> 404.
 */
class PromptTemplateController extends Controller
{
    public const MAX_BLOCKS = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = PromptTemplate::query()
            ->where('creator_id', $request->user()->id)
            ->withCount('configs')
            ->latest('updated_at')
            ->get();

        return PromptTemplateResource::collection($templates);
    }

    public function show(Request $request, int $template): PromptTemplateResource
    {
        return new PromptTemplateResource($this->find($request, $template)->loadCount('configs'));
    }

    public function store(Request $request): JsonResponse
    {
        $template = PromptTemplate::query()->create([...$this->validated($request), 'creator_id' => $request->user()->id]);

        return (new PromptTemplateResource($template->loadCount('configs')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $template): PromptTemplateResource
    {
        $template = $this->find($request, $template);
        $data = $this->validated($request);
        $prompt = PromptTemplate::compose($data['blocks']);

        DB::transaction(function () use ($template, $data, $prompt) {
            $configs = $template->configs()->with('creative:id,title')->lockForUpdate()->get();

            // Prompt gộp của Creative nào lỗi (quá dài, dùng biến Creative không có...) thì không lưu Template
            $broken = $configs->filter(fn (AiCreativeConfig $config) => CreativeTemplate::promptError(
                $config->input_schema ?? [],
                CreativeTemplate::mergePrompt($prompt, $config->ownPrompt()),
            ) !== null);
            if ($broken->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'blocks' => 'Prompt gộp của các Creative sau không hợp lệ: '
                        .$broken->map(fn ($c) => $c->creative?->title)->filter()->implode(', ').'.',
                ]);
            }

            $template->update($data);
            $configs->each(fn (AiCreativeConfig $config) => $config->update([
                'prompt_template' => CreativeTemplate::mergePrompt($prompt, $config->ownPrompt()),
            ]));
        });

        return new PromptTemplateResource($template->refresh()->loadCount('configs'));
    }

    public function destroy(Request $request, int $template): Response
    {
        $template = $this->find($request, $template);
        if ($template->configs()->exists()) {
            throw BusinessException::invalidState('Template đang được Creative sử dụng, hãy bỏ chọn Template ở các Creative đó trước khi xóa.');
        }
        $template->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'blocks' => ['required', 'array', 'min:1', 'max:'.self::MAX_BLOCKS],
            'blocks.*.title' => ['nullable', 'string', 'max:200'],
            'blocks.*.content' => ['nullable', 'string', 'max:'.CreativeTemplate::MAX_PROMPT_LENGTH],
        ]);

        $blocks = array_values(array_map(fn (array $b) => [
            'title' => trim((string) ($b['title'] ?? '')),
            'content' => trim((string) ($b['content'] ?? '')),
        ], $data['blocks']));
        $prompt = PromptTemplate::compose($blocks);
        if ($prompt === '') {
            throw ValidationException::withMessages(['blocks' => 'Template cần ít nhất 1 block có nội dung.']);
        }
        if (mb_strlen($prompt) > CreativeTemplate::MAX_PROMPT_LENGTH) {
            throw ValidationException::withMessages([
                'blocks' => 'Prompt Template tối đa '.number_format(CreativeTemplate::MAX_PROMPT_LENGTH, 0, ',', '.').' ký tự.',
            ]);
        }

        return ['title' => trim($data['title']), 'blocks' => $blocks];
    }

    private function find(Request $request, int $id): PromptTemplate
    {
        return PromptTemplate::query()->where('creator_id', $request->user()->id)->findOrFail($id);
    }
}
