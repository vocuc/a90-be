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
        $creative->loadMissing(['config', 'model']);
        $config = $creative->config;
        $fields = $config->input_schema;
        $data = $request->validate(CreativeTemplate::inputRules($fields, $maxImageKb, GenerationService::allowedAspectRatios($creative)));
        $inputs = $data['inputs'];

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
