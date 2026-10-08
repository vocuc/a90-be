<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Data\GeneratedImage;
use App\Services\AI\Data\ImageGenerationRequest;
use App\Services\AI\Exceptions\GenerationRejectedException;
use App\Services\AI\Exceptions\InvalidApiKeyException;
use App\Services\AI\Exceptions\RateLimitedException;
use App\Services\AI\Exceptions\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Gemini (Generative Language API) - tạo ảnh bằng generateContent.
 */
class GeminiImageProvider implements ImageProvider
{
    private const BLOCKED_FINISH_REASONS = [
        'SAFETY', 'IMAGE_SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION', 'IMAGE_PROHIBITED_CONTENT',
    ];

    public function generate(ImageGenerationRequest $request, string $apiKey): GeneratedImage
    {
        $generationConfig = array_merge($request->extraParams, [
            'responseModalities' => ['IMAGE'],
            'imageConfig' => ['aspectRatio' => $request->aspectRatio],
        ]);

        $response = $this->send(fn () => $this->client($apiKey)->post(
            "models/{$request->modelCode}:generateContent",
            [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => $request->prompt],
                        ['inline_data' => [
                            'mime_type' => $request->inputMime,
                            'data' => base64_encode($request->inputImage),
                        ]],
                    ],
                ]],
                'generationConfig' => $generationConfig,
            ],
        ));

        $this->throwIfFailed($response);

        $body = $response->json();

        if ($blockReason = data_get($body, 'promptFeedback.blockReason')) {
            throw new GenerationRejectedException("Gemini chặn yêu cầu: {$blockReason}", 'CONTENT_BLOCKED');
        }

        $candidate = data_get($body, 'candidates.0', []);
        foreach (data_get($candidate, 'content.parts', []) as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if ($inline && ! empty($inline['data'])) {
                return new GeneratedImage(
                    base64_decode($inline['data']),
                    $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png',
                );
            }
        }

        $finishReason = $candidate['finishReason'] ?? null;
        if (in_array($finishReason, self::BLOCKED_FINISH_REASONS, true)) {
            throw new GenerationRejectedException("Gemini chặn kết quả: {$finishReason}", 'CONTENT_BLOCKED');
        }

        throw new GenerationRejectedException('Gemini không trả về ảnh.', 'NO_IMAGE_RETURNED');
    }

    public function verifyKey(string $apiKey): void
    {
        $response = $this->send(fn () => $this->client($apiKey)->timeout(15)->get('models', ['pageSize' => 1]));

        $this->throwIfFailed($response);
    }

    private function client(string $apiKey): PendingRequest
    {
        return Http::baseUrl(rtrim(config('ai90.providers.gemini.base_url'), '/').'/')
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(config('ai90.providers.gemini.timeout'));
    }

    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new TransientProviderException('Không kết nối được Gemini: '.$e->getMessage(), 0, $e);
        }
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $message = Str::limit((string) $response->json('error.message', $response->body()), 300);
        $reason = (string) $response->json('error.details.0.reason', '');
        $errorStatus = (string) $response->json('error.status', '');

        // Gemini trả 400 + API_KEY_INVALID khi key sai.
        if ($status === 401 || $status === 403 || $reason === 'API_KEY_INVALID' || Str::contains($message, 'API key not valid')) {
            throw new InvalidApiKeyException($message);
        }

        if ($status === 429 || $errorStatus === 'RESOURCE_EXHAUSTED') {
            throw new RateLimitedException($message);
        }

        if ($status >= 500 || $status === 408) {
            throw new TransientProviderException("Gemini lỗi {$status}: {$message}");
        }

        throw new GenerationRejectedException("Gemini từ chối yêu cầu ({$status}): {$message}", 'PROVIDER_BAD_REQUEST');
    }
}
