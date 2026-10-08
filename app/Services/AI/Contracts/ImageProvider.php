<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\Data\GeneratedImage;
use App\Services\AI\Data\ImageGenerationRequest;
use App\Services\AI\Exceptions\GenerationRejectedException;
use App\Services\AI\Exceptions\InvalidApiKeyException;
use App\Services\AI\Exceptions\RateLimitedException;
use App\Services\AI\Exceptions\TransientProviderException;

interface ImageProvider
{
    /**
     * Tạo 1 ảnh.
     *
     * @throws InvalidApiKeyException
     * @throws RateLimitedException
     * @throws TransientProviderException
     * @throws GenerationRejectedException
     */
    public function generate(ImageGenerationRequest $request, string $apiKey): GeneratedImage;

    /**
     * Kiểm tra key có dùng được không.
     *
     * @throws InvalidApiKeyException
     * @throws TransientProviderException
     */
    public function verifyKey(string $apiKey): void;
}
