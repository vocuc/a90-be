<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Giao việc cho worker: mỗi job là 1 ảnh (generation_output).
 * Worker nhận job sẽ giữ nó trong lease_minutes; quá hạn thì lệnh sweep giao lại hoặc đánh dấu thất bại.
 */
class WorkerJobService
{
    /** Cạnh dài tối đa (px) của thumbnail ảnh kết quả. */
    public const THUMB_MAX_SIDE = 600;

    public function __construct(
        private GenerationFinalizer $finalizer,
        private MediaStorage $media,
    ) {}

    /** Nhận ảnh đang chờ cũ nhất. SKIP LOCKED để nhiều worker gọi cùng lúc không nhận trùng. */
    public function claim(Worker $worker): ?GenerationOutput
    {
        return DB::transaction(function () use ($worker) {
            $output = GenerationOutput::query()
                ->where('status', OutputStatus::Queued)
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if (! $output) {
                return null;
            }

            $output->update([
                'status' => OutputStatus::Processing,
                'worker_id' => $worker->id,
                'attempts' => $output->attempts + 1,
                'lease_expires_at' => now()->addMinutes(config('ai90.worker.lease_minutes')),
                'started_at' => $output->started_at ?? now(),
            ]);

            Generation::query()
                ->whereKey($output->generation_id)
                ->where('status', GenerationStatus::Queued)
                ->update(['status' => GenerationStatus::Processing]);

            return $output;
        });
    }

    public function complete(Worker $worker, GenerationOutput $output, UploadedFile $image): void
    {
        $this->assertAssigned($worker, $output);

        // Lưu ảnh trước, ngoài transaction, để không giữ khóa trong lúc upload lên S3.
        $directory = MediaStorage::datedDirectory('generations/outputs');
        $path = $this->media->storeUpload($image, $directory);
        $thumbPath = $this->storeThumb($image, $directory);

        try {
            DB::transaction(function () use ($worker, $output, $path, $thumbPath, $image) {
                $this->assertAssigned($worker, $this->lockOutput($output));
                $this->finalizer->complete($output->id, $path, $thumbPath, $image->getMimeType() ?: 'application/octet-stream', $image->getSize());
            });
        } catch (BusinessException $e) {
            $this->media->delete($path);
            $this->media->delete($thumbPath);
            throw $e;
        }
    }

    /** Ảnh gốc giữ nguyên để tải về / đăng bài; thumbnail chỉ để hiển thị danh sách. Lỗi tạo thumbnail không chặn kết quả. */
    private function storeThumb(UploadedFile $image, string $directory): ?string
    {
        try {
            return $this->media->storeOptimizedImage($image, $directory, [self::THUMB_MAX_SIDE])[0];
        } catch (BusinessException) {
            return null;
        }
    }

    public function fail(Worker $worker, GenerationOutput $output, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $output, $errorCode, $errorMessage) {
            $this->assertAssigned($worker, $this->lockOutput($output));
            $this->finalizer->fail($output->id, $errorCode, $errorMessage ?: 'Worker không tạo được ảnh.');
        });
    }

    /**
     * Ảnh worker giữ quá hạn: còn lượt thì trả về hàng đợi, hết lượt thì thất bại và hoàn Credit.
     *
     * @return array{requeued: int, failed: int}
     */
    public function releaseExpiredLeases(): array
    {
        $expired = GenerationOutput::query()
            ->where('status', OutputStatus::Processing)
            ->where('lease_expires_at', '<', now())
            ->get(['id', 'attempts']);

        $requeued = 0;
        $failed = 0;
        foreach ($expired as $output) {
            if ($output->attempts >= config('ai90.worker.max_attempts')) {
                $this->finalizer->fail($output->id, 'TIMEOUT', 'Worker không trả kết quả sau '.$output->attempts.' lần nhận.');
                $failed++;

                continue;
            }

            // Điều kiện trong WHERE: worker vừa trả kết quả thì không bị giao lại.
            $requeued += GenerationOutput::query()
                ->whereKey($output->id)
                ->where('status', OutputStatus::Processing)
                ->where('lease_expires_at', '<', now())
                ->update(['status' => OutputStatus::Queued, 'worker_id' => null, 'lease_expires_at' => null]);
        }

        return ['requeued' => $requeued, 'failed' => $failed];
    }

    private function lockOutput(GenerationOutput $output): GenerationOutput
    {
        return GenerationOutput::query()->lockForUpdate()->findOrFail($output->id);
    }

    /** Chỉ worker đang giữ job mới được trả kết quả (job hết hạn đã giao cho worker khác thì từ chối). */
    private function assertAssigned(Worker $worker, GenerationOutput $output): void
    {
        if ($output->status !== OutputStatus::Processing || $output->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }
    }
}
