<?php

namespace App\Http\Requests;

use App\Models\AiModel;
use App\Services\SettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dữ liệu Creator gửi khi tạo/sửa Creative. Dùng cho cả POST (tạo) và PUT (sửa từng phần).
 */
class CreativeRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $limit = app(SettingService::class)->int('generation.max_outputs_limit');
        $ratios = $this->supportedRatios();

        return [
            'title' => [$required, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'model_id' => [$required, 'integer', Rule::exists('ai_models', 'id')->where('is_active', true)],
            'price_per_image' => [$required, 'integer', 'min:0', 'max:100000000'],
            'system_prompt' => ['nullable', 'string', 'max:10000'],
            // Danh sách câu lệnh [{label, value}], ghép thành prompt khi gọi model (ModelService::buildPrompt)
            'creative_prompt' => ['nullable', 'array', 'max:20'],
            'creative_prompt.*' => ['array:label,value'],
            'creative_prompt.*.label' => ['required', 'string', 'max:100'],
            'creative_prompt.*.value' => ['required', 'string', 'max:5000'],
            'negative_prompt' => ['nullable', 'string', 'max:5000'],
            'max_outputs' => ['sometimes', 'integer', 'min:1', "max:{$limit}"],
            'allowed_aspect_ratios' => ['nullable', 'array', 'min:1'],
            'allowed_aspect_ratios.*' => ['string', 'distinct', Rule::in($ratios)],
            'default_aspect_ratio' => ['sometimes', 'string', Rule::in($ratios)],
            'extra_params' => ['nullable', 'array:temperature,topP,topK,seed'],
            'extra_params.temperature' => ['numeric', 'min:0', 'max:2'],
            'extra_params.topP' => ['numeric', 'min:0', 'max:1'],
            'extra_params.topK' => ['integer', 'min:1', 'max:100'],
            'extra_params.seed' => ['integer'],
        ];
    }

    /** Tỉ lệ khung hình model (mới hoặc hiện tại) hỗ trợ. */
    private function supportedRatios(): array
    {
        $modelId = $this->input('model_id') ?? $this->route('creative')?->model_id;

        return AiModel::query()->find($modelId)?->supported_aspect_ratios ?? [];
    }
}
