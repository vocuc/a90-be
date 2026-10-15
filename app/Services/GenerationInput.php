<?php

namespace App\Services;

use App\Models\AiCreative;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Dữ liệu đầu vào của một lượt tạo ảnh: inputs[key] theo input_schema của Creative (ảnh, giá trị, inputs[aspectRatio]).
 */
final class GenerationInput
{
    /**
     * @param  array<string, UploadedFile>  $files  ảnh theo tên trường
     * @param  array<string, mixed>  $values  giá trị không phải ảnh theo tên trường
     */
    public function __construct(
        public readonly array $files,
        public readonly array $values,
        public readonly string $aspectRatio,
    ) {}

    public static function fromRequest(Request $request, AiCreative $creative, int $maxImageKb): self
    {
        $data = $request->validate(self::rules($creative, $maxImageKb));

        return self::fromValidated($data['inputs'], $creative);
    }

    /**
     * Rule cho inputs[key] của một Creative, đặt dưới $prefix (vd "items.0.") khi một request chứa nhiều mẫu.
     *
     * @param  string[]  $keptImageKeys  trường ảnh giữ file cũ (không bắt buộc tải lại)
     */
    public static function rules(AiCreative $creative, int $maxImageKb, string $prefix = '', array $keptImageKeys = []): array
    {
        $creative->loadMissing(['config', 'model']);
        $fields = array_map(fn (array $field) => $field['type'] === 'image' && in_array($field['key'], $keptImageKeys, true)
            ? [...$field, 'required' => false]
            : $field, $creative->config->input_schema);

        $rules = [];
        foreach (CreativeTemplate::inputRules($fields, $maxImageKb, GenerationService::allowedAspectRatios($creative)) as $key => $rule) {
            $rules[$prefix.$key] = $rule;
        }

        return $rules;
    }

    /** @param  array<string, mixed>  $inputs  inputs đã qua rules() */
    public static function fromValidated(array $inputs, AiCreative $creative): self
    {
        $creative->loadMissing(['config', 'model']);
        $config = $creative->config;
        $fields = CreativeTemplate::buyerFields($config->input_schema);

        $files = [];
        $values = [];
        foreach ($fields as $field) {
            $value = $inputs[$field['key']] ?? null;
            if ($field['type'] === 'image') {
                if ($value instanceof UploadedFile) {
                    $files[$field['key']] = $value;
                }
            } elseif ($value !== null) {
                $values[$field['key']] = $field['type'] === 'toggle' ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value;
            }
        }

        // Không chọn tỉ lệ thì dùng tỉ lệ mặc định Creator đặt
        $values[CreativeTemplate::ASPECT_KEY] ??= $config->default_aspect_ratio;

        return new self($files, $values, $values[CreativeTemplate::ASPECT_KEY]);
    }
}
