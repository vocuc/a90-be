<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\AI\ApiKeyRotator;
use App\Services\AI\Data\ImageGenerationRequest;
use App\Services\AI\Exceptions\GenerationRejectedException;
use App\Services\AI\Exceptions\InvalidApiKeyException;
use App\Services\AI\Exceptions\RateLimitedException;
use App\Services\AI\Exceptions\TransientProviderException;
use App\Services\AI\ModelService;
use App\Services\CreativeService;
use App\Services\GenerationFinalizer;
use App\Services\MediaStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tạo 1 ảnh của một Generation: chọn key của Creator, gọi AI, lưu kết quả, chốt Transaction.
 */
class ProcessGenerationOutput implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 300;

    /** @var int[] giây chờ trước mỗi lần thử lại khi Provider lỗi tạm thời */
    public array $backoff = [10, 30, 60];

    public function __construct(public int $outputId)
    {
        $this->afterCommit();
    }

    public function handle(
        GenerationFinalizer $finalizer,
        ApiKeyRotator $keys,
        ModelService $models,
        MediaStorage $media,
        CreativeService $creatives,
    ): void {
        $output = $finalizer->markProcessing($this->outputId);
        if (! $output) {
            return; // đã chốt hoặc không tồn tại
        }

        /** @var Generation $generation */
        $generation = $output->generation()->with(['model.provider', 'creative.config'])->firstOrFail();
        $model = $generation->model;
        $provider = $models->providerFor($model);

        $request = new ImageGenerationRequest(
            modelCode: $model->code,
            prompt: $generation->prompt_snapshot,
            inputImage: $media->get($generation->input_image_path),
            inputMime: $media->mimeType($generation->input_image_path),
            aspectRatio: $generation->aspect_ratio,
            extraParams: $generation->creative->config?->extra_params ?? [],
        );

        $triedKeyIds = [];
        $lastError = 'Creator không còn API key khả dụng.';

        while ($key = $keys->next($generation->creator_id, $model->provider_id, $triedKeyIds)) {
            $triedKeyIds[] = $key->id;

            try {
                $image = $provider->generate($request, $key->api_key);
            } catch (InvalidApiKeyException $e) {
                $keys->markInvalid($key, $e->getMessage());
                $creatives->pauseIfNoActiveKey($generation->creator_id, $model->provider_id);
                $lastError = 'API key của Creator không hợp lệ.';

                continue;
            } catch (RateLimitedException $e) {
                $keys->markCooldown($key, $e->getMessage());
                $lastError = 'API key của Creator đã hết hạn mức.';

                continue;
            } catch (TransientProviderException $e) {
                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff[$this->attempts() - 1] ?? 60);

                    return;
                }
                $finalizer->fail($output->id, $e->errorCode(), $e->getMessage(), $key->id);

                return;
            } catch (GenerationRejectedException $e) {
                $finalizer->fail($output->id, $e->errorCode(), $e->getMessage(), $key->id);

                return;
            }

            $path = MediaStorage::datedDirectory('generations/outputs')."/{$generation->id}-{$output->position}-".uniqid().'.'.$image->extension();
            $media->put($path, $image->bytes);
            $finalizer->complete($output->id, $path, $image->mime, strlen($image->bytes), $key->id);

            return;
        }

        $finalizer->fail($output->id, 'NO_USABLE_API_KEY', $lastError);
    }

    /** Job lỗi ngoài dự kiến hoặc hết số lần thử: đánh dấu thất bại và hoàn Credit. */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessGenerationOutput failed', ['output_id' => $this->outputId, 'error' => $exception?->getMessage()]);

        app(GenerationFinalizer::class)->fail($this->outputId, 'INTERNAL_ERROR', 'Lỗi hệ thống khi tạo ảnh.');
    }
}
