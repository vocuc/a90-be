<?php

namespace App\Http\Requests;

use App\Models\AiModel;
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
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $limit = app(SettingService::class)->int('generation.max_outputs_limit');
        $ratios = $this->supportedRatios();

        return [
            'title' => [$required, 'string', 'max:150'],
            // Template Builder hiển thị là "Hướng dẫn sử dụng mẫu"
            'description' => ['nullable', 'string', 'max:5000'],
            'video_url' => ['nullable', 'string', 'max:500', 'url:http,https', $this->tiktokUrl(...)],
            // Form người mua + Prompt Template, luôn gửi cùng nhau (kiểm tra chi tiết ở after()).
            // Tỉ lệ mặc định lấy từ trường tỉ lệ khung hình trong input_schema.
            'input_schema' => [$required, 'array', 'required_with:prompt_template'],
            'prompt_template' => [$required, 'string', 'required_with:input_schema'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'model_id' => [$required, 'integer', Rule::exists('ai_models', 'id')->where('is_active', true)],
            'price_per_image' => [$required, 'integer', 'min:0', 'max:100000000'],
            'max_outputs' => ['sometimes', 'integer', 'min:1', "max:{$limit}"],
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
                $errors = CreativeTemplate::errors(
                    $this->input('input_schema'),
                    $this->input('prompt_template'),
                    $this->supportedRatios(),
                    app(SettingService::class)->int('generation.input_max_size_kb'),
                );
                foreach ($errors as $path => $message) {
                    $validator->errors()->add($path, $message);
                }
            },
        ];
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
