<?php

namespace App\Services;

use Illuminate\Validation\Rule;

/**
 * Template Builder: form người mua (input_schema) + Prompt Template có {{biến}}.
 * Khớp frontend src/components/template/templateSchema.js.
 *
 * input_schema là danh sách trường: { key, label, type, description?, required, sort_order, validation, options?, default_value? }.
 * Trường hidden_image (nguyên liệu ẩn) có thêm asset_id: ảnh Creator tải lên, người mua không thấy, luôn gửi kèm tới model.
 */
class CreativeTemplate
{
    /** Loại trường backend xử lý được (frontend còn image_list/image_set nhưng chưa hỗ trợ khi tạo ảnh). */
    public const TYPES = ['image', 'text', 'textarea', 'number', 'select', 'radio', 'multi_select', 'toggle', 'aspect_ratio', self::HIDDEN_IMAGE];

    /** Nguyên liệu ẩn: không có trong form người mua. */
    public const HIDDEN_IMAGE = 'hidden_image';

    private const OPTION_TYPES = ['select', 'radio', 'multi_select'];

    /** Trường tỉ lệ khung hình: bắt buộc, đúng 1 trường, tên cố định. */
    public const ASPECT_KEY = 'aspectRatio';

    public const MAX_FIELDS = 20;

    public const MAX_PROMPT_LENGTH = 10000;

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/';

    private const VARIABLE_PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    private const IMAGE_MIMES = ['image/jpeg' => 'jpg,jpeg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Kiểm tra schema + prompt Creator gửi lên.
     *
     * @param  string[]  $supportedRatios  tỉ lệ model hỗ trợ
     * @param  int  $maxImageKb  dung lượng ảnh đầu vào tối đa (setting generation.input_max_size_kb)
     * @param  int  $maxHiddenImages  số nguyên liệu ẩn tối đa (setting generation.max_hidden_images)
     * @return array<string, string> lỗi theo đường dẫn, rỗng = hợp lệ
     */
    public static function errors(array $fields, ?string $prompt, array $supportedRatios, int $maxImageKb, int $maxHiddenImages): array
    {
        $errors = [];
        $add = function (string $path, string $message) use (&$errors) {
            $errors[$path] ??= $message;
        };

        if (! array_is_list($fields) || count($fields) === 0) {
            $add('input_schema', 'Template cần ít nhất 1 trường.');

            return $errors;
        }
        if (count($fields) > self::MAX_FIELDS) {
            $add('input_schema', 'Tối đa '.self::MAX_FIELDS.' trường.');
        }

        $keys = [];
        $ratioFields = 0;
        $hiddenImages = 0;
        foreach ($fields as $i => $field) {
            $path = "input_schema.{$i}";
            if (! is_array($field)) {
                $add($path, 'Trường không hợp lệ.');

                continue;
            }

            $type = $field['type'] ?? null;
            $key = $field['key'] ?? null;
            $name = '"'.(is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : ($key ?: 'Trường #'.($i + 1))).'"';

            if (! in_array($type, self::TYPES, true)) {
                $add("{$path}.type", "{$name}: loại trường không được hỗ trợ.");

                continue;
            }

            if ($type === 'aspect_ratio') {
                $ratioFields++;
                if ($key !== self::ASPECT_KEY) {
                    $add("{$path}.key", 'Tên trường tỉ lệ khung hình phải là '.self::ASPECT_KEY.'.');
                }
                if (! in_array($field['default_value'] ?? null, $supportedRatios, true)) {
                    $add("{$path}.default_value", 'Tỉ lệ khung hình mặc định không được model hỗ trợ.');
                }
            } elseif (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
                $add("{$path}.key", "{$name}: tên trường chỉ gồm chữ thường không dấu, số, dấu _ và bắt đầu bằng chữ (2–50 ký tự).");
            }

            if (is_string($key)) {
                if (isset($keys[$key])) {
                    $add("{$path}.key", "Tên trường \"{$key}\" bị trùng.");
                }
                $keys[$key] = $type;
            }

            $label = $field['label'] ?? null;
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                $add("{$path}.label", 'Trường #'.($i + 1).': label hiển thị từ 1 đến 100 ký tự.');
            }
            if (isset($field['description']) && (! is_string($field['description']) || mb_strlen($field['description']) > 500)) {
                $add("{$path}.description", "{$name}: mô tả tối đa 500 ký tự.");
            }

            if ($type === self::HIDDEN_IMAGE) {
                $hiddenImages++;
                if (! is_int($field['asset_id'] ?? null)) {
                    $add("{$path}.asset_id", "{$name}: chưa tải ảnh nguyên liệu.");
                }
            }

            $v = is_array($field['validation'] ?? null) ? $field['validation'] : [];
            if ($type === 'image') {
                $types = $v['allowed_types'] ?? array_keys(self::IMAGE_MIMES);
                if (! is_array($types) || ! $types || array_diff($types, array_keys(self::IMAGE_MIMES))) {
                    $add("{$path}.allowed_types", "{$name}: định dạng ảnh chỉ gồm JPG, PNG, WEBP.");
                }
                $maxMb = $v['max_size_mb'] ?? null;
                if ($maxMb !== null && (! is_numeric($maxMb) || $maxMb <= 0 || $maxMb * 1024 > $maxImageKb)) {
                    $add("{$path}.max_size_mb", "{$name}: dung lượng mỗi ảnh tối đa ".intdiv($maxImageKb, 1024).'MB.');
                }
            }
            if (in_array($type, ['text', 'textarea'], true)) {
                $max = $v['max_length'] ?? null;
                if ($max !== null && (! is_int($max) || $max < 1 || $max > 5000)) {
                    $add("{$path}.max_length", "{$name}: độ dài tối đa từ 1 đến 5000 ký tự.");
                }
            }
            if ($type === 'number') {
                $min = $v['min'] ?? null;
                $max = $v['max'] ?? null;
                if (($min !== null && ! is_numeric($min)) || ($max !== null && ! is_numeric($max)) || ($min !== null && $max !== null && $min > $max)) {
                    $add("{$path}.min", "{$name}: giới hạn số không hợp lệ.");
                }
            }
            if (in_array($type, self::OPTION_TYPES, true)) {
                $options = $field['options'] ?? null;
                $values = is_array($options) ? array_map(fn ($o) => is_array($o) ? ($o['value'] ?? null) : null, $options) : [];
                $valid = is_array($options) && count($options) >= 2 && count($options) <= 50
                    && collect($options)->every(fn ($o) => is_array($o)
                        && is_string($o['value'] ?? null) && trim($o['value']) !== '' && mb_strlen($o['value']) <= 100
                        && is_string($o['label'] ?? null) && trim($o['label']) !== '' && mb_strlen($o['label']) <= 100);
                if (! $valid) {
                    $add("{$path}.options", "{$name}: cần từ 2 đến 50 lựa chọn, mỗi lựa chọn có giá trị và nhãn.");
                } elseif (count(array_unique($values)) !== count($values)) {
                    $add("{$path}.options", "{$name}: giá trị lựa chọn bị trùng.");
                }
            }
        }

        if ($ratioFields !== 1) {
            $add('input_schema', 'Template phải có đúng 1 trường tỉ lệ khung hình.');
        }
        if ($hiddenImages > $maxHiddenImages) {
            $add('input_schema', $maxHiddenImages > 0
                ? "Tối đa {$maxHiddenImages} nguyên liệu ẩn."
                : 'Hiện chưa cho phép dùng nguyên liệu ẩn.');
        }

        $prompt = (string) $prompt;
        if (trim($prompt) === '') {
            $add('prompt_template', 'Chưa viết Prompt Template.');
        } elseif (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            $add('prompt_template', 'Prompt tối đa '.number_format(self::MAX_PROMPT_LENGTH, 0, ',', '.').' ký tự.');
        }
        foreach (self::variables($prompt) as $variable) {
            if (! isset($keys[$variable])) {
                $add('prompt_template', "Prompt dùng biến {{{$variable}}} chưa khai báo trong Template.");
            } elseif (in_array($keys[$variable], ['image', self::HIDDEN_IMAGE], true)) {
                $add('prompt_template', "{{{$variable}}} là trường ảnh — ảnh được gửi kèm, không chèn vào prompt như chữ.");
            }
        }

        return $errors;
    }

    /**
     * Chỉ giữ các thuộc tính backend dùng, đánh lại sort_order theo thứ tự gửi lên.
     */
    public static function normalize(array $fields): array
    {
        return array_values(array_map(function (array $field, int $i) {
            $type = $field['type'];
            $out = [
                'key' => $field['key'],
                'label' => trim($field['label']),
                'type' => $type,
                'description' => isset($field['description']) && trim($field['description']) !== '' ? trim($field['description']) : null,
                // Trường tỉ lệ luôn bắt buộc (người mua luôn có tỉ lệ được chọn sẵn)
                'required' => $type === 'aspect_ratio' || (bool) ($field['required'] ?? false),
                'sort_order' => $i + 1,
                'validation' => is_array($field['validation'] ?? null) ? $field['validation'] : (object) [],
            ];
            if (in_array($type, self::OPTION_TYPES, true)) {
                $out['options'] = array_map(fn ($o) => ['value' => trim($o['value']), 'label' => trim($o['label'])], $field['options']);
            }
            if ($type === 'aspect_ratio') {
                $out['options'] = [['value' => $field['default_value'], 'label' => $field['default_value']]];
            }
            if ($type === self::HIDDEN_IMAGE) {
                $out['required'] = false;
                $out['asset_id'] = $field['asset_id'];
            }
            if (array_key_exists('default_value', $field) && ! in_array($type, ['image', self::HIDDEN_IMAGE], true)) {
                $out['default_value'] = $field['default_value'];
            }

            return $out;
        }, $fields, array_keys($fields)));
    }

    /** @return string[] tên biến {{key}} dùng trong prompt */
    public static function variables(string $prompt): array
    {
        preg_match_all(self::VARIABLE_PATTERN, $prompt, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** Các trường người mua thấy (bỏ nguyên liệu ẩn). */
    public static function buyerFields(array $fields): array
    {
        return array_values(array_filter($fields, fn (array $field) => $field['type'] !== self::HIDDEN_IMAGE));
    }

    /** @return array<string, int> asset_id theo tên trường của các nguyên liệu ẩn */
    public static function hiddenAssetIds(array $fields): array
    {
        return collect($fields)->where('type', self::HIDDEN_IMAGE)->pluck('asset_id', 'key')->all();
    }

    public static function aspectField(array $fields): ?array
    {
        return collect($fields)->firstWhere('type', 'aspect_ratio');
    }

    /**
     * Rule Laravel cho dữ liệu người mua gửi khi tạo ảnh: inputs[key].
     *
     * @param  string[]  $ratios  tỉ lệ người mua được chọn
     */
    public static function inputRules(array $fields, int $maxImageKb, array $ratios): array
    {
        $rules = ['inputs' => ['required', 'array']];
        foreach (self::buyerFields($fields) as $field) {
            $path = 'inputs.'.$field['key'];
            $required = $field['required'] ? 'required' : 'nullable';
            $v = (array) ($field['validation'] ?? []);

            $rules[$path] = match ($field['type']) {
                'image' => [$required, 'image', 'mimes:'.self::mimes($v['allowed_types'] ?? null),
                    'max:'.min($maxImageKb, isset($v['max_size_mb']) ? (int) ($v['max_size_mb'] * 1024) : $maxImageKb)],
                'text', 'textarea' => [$required, 'string', 'max:'.($v['max_length'] ?? 5000)],
                'number' => array_values(array_filter([$required, 'numeric',
                    isset($v['min']) ? 'min:'.$v['min'] : null, isset($v['max']) ? 'max:'.$v['max'] : null])),
                'select', 'radio' => [$required, 'string', Rule::in(array_column($field['options'], 'value'))],
                'multi_select' => [$required, 'array'],
                'toggle' => ['nullable', 'boolean'],
                // Người mua đổi được sang mọi tỉ lệ model hỗ trợ; không gửi thì dùng tỉ lệ mặc định
                'aspect_ratio' => ['nullable', 'string', Rule::in($ratios)],
            };
            if ($field['type'] === 'multi_select') {
                $rules[$path.'.*'] = ['string', 'distinct', Rule::in(array_column($field['options'], 'value'))];
            }
        }

        return $rules;
    }

    private static function mimes(?array $allowed): string
    {
        $allowed = $allowed ?: array_keys(self::IMAGE_MIMES);

        return implode(',', array_map(fn ($mime) => self::IMAGE_MIMES[$mime], array_intersect($allowed, array_keys(self::IMAGE_MIMES))));
    }

    /**
     * Thay {{key}} bằng giá trị người mua nhập. Biến của trường ảnh (kể cả ảnh ẩn) hoặc chưa có giá trị -> chuỗi rỗng.
     *
     * @param  array<string, mixed>  $values
     */
    public static function render(string $prompt, array $fields, array $values): string
    {
        $byKey = collect($fields)->keyBy('key');

        $text = preg_replace_callback(self::VARIABLE_PATTERN, function ($m) use ($byKey, $values) {
            $field = $byKey->get($m[1]);
            if (! $field || in_array($field['type'], ['image', self::HIDDEN_IMAGE], true)) {
                return '';
            }

            return self::display($field, $values[$m[1]] ?? null);
        }, $prompt);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** Giá trị hiển thị trong prompt: lựa chọn dùng nhãn, bật/tắt thành Có/Không. */
    private static function display(array $field, mixed $value): string
    {
        if ($field['type'] === 'toggle') {
            return $value ? 'Có' : 'Không';
        }
        if (in_array($field['type'], self::OPTION_TYPES, true)) {
            $labels = collect($field['options'])->pluck('label', 'value');
            $list = is_array($value) ? $value : [$value];

            return implode(', ', array_map(fn ($v) => (string) ($labels[$v] ?? $v), array_filter($list, fn ($v) => $v !== null && $v !== '')));
        }

        return trim((string) $value);
    }
}
