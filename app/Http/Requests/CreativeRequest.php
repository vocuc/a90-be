<?php

namespace App\Http\Requests;

use App\Models\AiCreativeAsset;
use App\Models\AiModel;
use App\Models\Hashtag;
use App\Models\PromptTemplate;
use App\Services\CreativeTemplate;
use App\Services\SettingService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Dữ liệu Creator gửi khi tạo/sửa Creative. Dùng cho cả POST (tạo) và PUT (sửa từng phần).
 */
class CreativeRequest extends FormRequest
{
    public const MAX_HASHTAGS = 10;

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $limit = app(SettingService::class)->int('generation.max_outputs_limit');
        $ratios = $this->supportedRatios();

        return [
            // Bỏ trống -> tự đặt MS{YmdHis} (CreativeService::defaultTitle)
            'title' => ['nullable', 'string', 'max:150'],
            // Template Builder hiển thị là "Hướng dẫn sử dụng mẫu"
            'description' => ['nullable', 'string', 'max:5000'],
            'video_url' => ['nullable', 'string', 'max:500', 'url:http,https', $this->tiktokUrl(...)],
            // Form người mua + Prompt Template, luôn gửi cùng nhau (kiểm tra chi tiết ở after()).
            // Tỉ lệ mặc định lấy từ trường tỉ lệ khung hình trong input_schema.
            'input_schema' => [$required, 'array', 'required_with:prompt_template'],
            // Prompt riêng của Creative. Chọn Template (prompt_template_id) thì được bỏ trống, prompt cuối = Template + prompt này
            'prompt_template' => [$this->isMethod('POST') ? 'required_without:prompt_template_id' : 'sometimes', 'nullable', 'string'],
            'prompt_template_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('prompt_templates', 'id')->where('creator_id', $this->user()->id)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'model_id' => [$required, 'integer', Rule::exists('ai_models', 'id')->where('is_active', true)],
            // false (mặc định khi tạo) = private: chỉ Creator thấy, không hiện trên Marketplace
            'is_public' => ['sometimes', 'boolean'],
            'max_outputs' => ['sometimes', 'integer', 'min:1', "max:{$limit}"],
            // Gửi cả danh sách để thay thế; [] để xóa hết. Có hoặc không có # đều được.
            'hashtags' => ['sometimes', 'array', 'max:'.self::MAX_HASHTAGS],
            'hashtags.*' => ['required', 'string', 'max:50', 'regex:'.Hashtag::PATTERN, $this->hashtag(...)],
        ];
    }

    /** Kiểm tra chi tiết template sau khi các rule cơ bản đã qua. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! is_array($this->input('input_schema'))) {
                    return;
                }
                $settings = app(SettingService::class);
                $errors = CreativeTemplate::errors(
                    $this->input('input_schema'),
                    $this->mergedPrompt(),
                    $this->supportedRatios(),
                    $settings->int('generation.input_max_size_kb'),
                    $settings->int('generation.max_hidden_images'),
                );
                if (! isset($errors['prompt_template']) && $message = $this->optionsError()) {
                    $errors['prompt_template'] = $message;
                }
                $errors += $this->assetErrors($this->input('input_schema'));
                foreach ($errors as $path => $message) {
                    $validator->errors()->add($path, $message);
                }
            },
        ];
    }

    /** Prompt cuối cùng sẽ lưu: prompt Template (gửi lên hoặc đang dùng) + prompt riêng (gửi lên hoặc đang có). */
    private function mergedPrompt(): string
    {
        $config = $this->route('creative')?->config;
        $templateId = $this->has('prompt_template_id') ? $this->input('prompt_template_id') : $config?->prompt_template_id;
        $own = $this->has('prompt_template') ? (string) $this->input('prompt_template') : (string) $config?->ownPrompt();

        return PromptTemplate::mergeFor($templateId ? (int) $templateId : null, $own);
    }

    /** Option trong các block của Template (gửi lên hoặc đang dùng) phải chỉ dùng biến có trong input_schema. */
    private function optionsError(): ?string
    {
        $templateId = $this->has('prompt_template_id') ? $this->input('prompt_template_id') : $this->route('creative')?->config?->prompt_template_id;
        $template = $templateId ? PromptTemplate::query()->find((int) $templateId) : null;

        return $template ? PromptTemplate::optionsError($this->input('input_schema'), $template->blocks ?? []) : null;
    }

    /**
     * Ảnh nguyên liệu ẩn phải do chính Creator tải lên, chưa gắn Creative nào hoặc thuộc Creative đang sửa.
     *
     * @return array<string, string>
     */
    private function assetErrors(array $fields): array
    {
        $ids = [];
        foreach ($fields as $i => $field) {
            if (is_array($field) && ($field['type'] ?? null) === CreativeTemplate::HIDDEN_IMAGE && is_int($field['asset_id'] ?? null)) {
                $ids[$i] = $field['asset_id'];
            }
        }
        if (! $ids) {
            return [];
        }

        $creativeId = $this->route('creative')?->id;
        $valid = AiCreativeAsset::query()
            ->whereIn('id', $ids)
            ->where('creator_id', $this->user()->id)
            ->where(fn ($q) => $q->whereNull('creative_id')->when($creativeId, fn ($q) => $q->orWhere('creative_id', $creativeId)))
            ->pluck('id')
            ->all();

        $errors = [];
        foreach (array_diff($ids, $valid) as $i => $id) {
            $errors["input_schema.{$i}.asset_id"] = 'Ảnh nguyên liệu không tồn tại, hãy tải lại.';
        }

        return $errors;
    }

    /** Hashtag phải còn ký tự Latin/số sau khi bỏ dấu (dùng làm khóa so trùng). */
    private function hashtag(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Hashtag::slugFor($value) === '') {
            $fail('Hashtag phải chứa chữ cái hoặc chữ số.');
        }
    }

    /** Link video hướng dẫn chỉ nhận TikTok (tiktok.com và tên miền con: www., m., vt., vm.). */
    private function tiktokUrl(string $attribute, mixed $value, Closure $fail): void
    {
        $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));
        $path = (string) parse_url((string) $value, PHP_URL_PATH);
        if (! preg_match('/(^|\.)tiktok\.com$/', $host) || strlen($path) <= 1) {
            $fail('Link video hướng dẫn phải là link TikTok.');
        }
    }

    /** Tỉ lệ khung hình model (mới hoặc hiện tại) hỗ trợ. */
    private function supportedRatios(): array
    {
        $modelId = $this->input('model_id') ?? $this->route('creative')?->model_id;

        return AiModel::query()->find($modelId)?->supported_aspect_ratios ?? [];
    }
}
