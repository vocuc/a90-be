<?php

namespace App\Services\AI\Data;

final class ImageGenerationRequest
{
    public function __construct(
        public readonly string $modelCode,
        public readonly string $prompt,
        public readonly string $inputImage,
        public readonly string $inputMime,
        public readonly string $aspectRatio,
        public readonly array $extraParams = [],
    ) {}
}
