<?php

namespace App\Services\AI;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Providers\GeminiImageProvider;
use InvalidArgumentException;

/**
 * Xác định adapter gọi API cho từng Provider. Thêm Provider mới: thêm 1 dòng vào PROVIDERS.
 */
class ModelService
{
    private const PROVIDERS = [
        'gemini' => GeminiImageProvider::class,
    ];

    public function providerFor(AiModel $model): ImageProvider
    {
        return $this->provider($model->provider);
    }

    public function provider(AiProvider $provider): ImageProvider
    {
        $class = self::PROVIDERS[$provider->code]
            ?? throw new InvalidArgumentException("Chưa hỗ trợ AI Provider [{$provider->code}].");

        return app($class);
    }

    /** Gộp System / Creative / Negative prompt thành một prompt gửi sang model. */
    public static function buildPrompt(?string $systemPrompt, ?array $creativePrompt, ?string $negativePrompt): string
    {
        $parts = array_filter([
            trim((string) $systemPrompt),
            self::formatCreativePrompt($creativePrompt),
            trim((string) $negativePrompt) !== '' ? 'Avoid: '.trim((string) $negativePrompt) : '',
        ], fn ($part) => $part !== '');

        return implode("\n\n", $parts);
    }

    /** Danh sách câu lệnh [{label, value}] -> mỗi câu lệnh một dòng "label: value". */
    public static function formatCreativePrompt(?array $groups): string
    {
        $lines = array_map(function ($group) {
            $label = trim((string) ($group['label'] ?? ''));
            $value = trim((string) ($group['value'] ?? ''));

            return $value === '' ? '' : ($label === '' ? $value : "{$label}: {$value}");
        }, $groups ?? []);

        return implode("\n", array_filter($lines, fn ($line) => $line !== ''));
    }
}
