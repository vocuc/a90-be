<?php

namespace App\Services;

/**
 * Gộp System / Creative / Negative prompt của Creative thành một prompt gửi cho worker.
 */
class PromptBuilder
{
    public static function build(?string $systemPrompt, ?array $creativePrompt, ?string $negativePrompt): string
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
