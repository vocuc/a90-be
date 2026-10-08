<?php

namespace App\Services\AI\Data;

final class GeneratedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
    ) {}

    public function extension(): string
    {
        return match ($this->mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }
}
