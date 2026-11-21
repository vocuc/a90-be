<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\ImageJob;
use App\Models\Worker;
use App\Models\WorkerJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Giao việc TẠO ẢNH cho worker (worker_jobs type image | outfit, a90-fe note/gop-viec-worker.txt): mỗi việc là 1 ảnh
 * (generation_output). Worker nhận việc giữ nó trong lease_minutes; quá hạn thì lệnh sweep giao lại hoặc đánh dấu thất bại.
 */
class WorkerJobService
{
    /** Cạnh dài tối đa (px) của thumbnail ảnh kết quả. */
    public const THUMB_MAX_SIDE = 600;

    public function __construct(
        private GenerationFinalizer $finalizer,
        private MediaStorage $media,
    ) {}

    /**
     * Nhận ảnh đang chờ cũ nhất trong các job do tài khoản gắn với worker tạo (chưa gắn tài khoản nào -> không có job).
     * Job người dùng đã chọn worker (generations.worker_id) chỉ worker đó nhận; worker đã chọn bị tắt / đổi loại / gỡ khỏi
     * tài khoản thì worker tạo ảnh khác của tài khoản nhận thay để ảnh không bị treo (note/chon-worker-tao-anh.txt).
     * SKIP LOCKED để nhiều worker gọi cùng lúc không nhận trùng.
     */
    public function claim(Worker $worker): ?ImageJob
    {
        if ($worker->type !== Worker::TYPE_OUTFIT) {
            $worker->assertType(Worker::TYPE_IMAGE);
        }
        // Worker image chỉ nhận ảnh creative; worker outfit chỉ nhận ảnh của lô phối đồ
        $kinds = $worker->type === Worker::TYPE_OUTFIT ? Generation::OUTFIT_KINDS : [Generation::KIND_CREATIVE];

        return DB::transaction(function () use ($worker, $kinds) {
            $generations = Generation::query()
                ->select('id')
                ->whereIn('kind', $kinds)
                ->whereIn('user_id', DB::table('worker_user')->select('user_id')->where('worker_id', $worker->id))
                ->where(fn ($q) => $q->whereNull('generations.worker_id')
                    ->orWhere('generations.worker_id', $worker->id)
                    ->orWhereNotExists(fn ($chosen) => $chosen->from('workers')
                        ->join('worker_user', 'worker_user.worker_id', '=', 'workers.id')
                        ->whereColumn('workers.id', 'generations.worker_id')
                        ->whereColumn('worker_user.user_id', 'generations.user_id')
                        ->where('workers.is_active', true)
                        ->where('workers.type', $worker->type)));

            $pending = [OutputStatus::Queued->value, OutputStatus::Processing->value];
            $output = GenerationOutput::query()
                ->where('status', OutputStatus::Queued)
                ->whereIn('generation_id', $generations)
                // Lô phối đồ CÓ sản phẩm (2026-11-16): ảnh bìa làm TRƯỚC — worker viết hook bìa = tiêu đề lô, các ảnh outfit
                // chọn outfit theo tiêu đề đó -> chưa giao ảnh outfit (vị trí > 1) khi ảnh bìa cùng lô còn đang chờ / đang tạo
                // (bìa lỗi thì ảnh outfit vẫn được giao, worker tự chọn chủ đề)
                ->whereNotExists(fn ($q) => $q->from('image_batch_images as ob')
                    ->join('image_batch_images as cb', 'cb.image_batch_id', '=', 'ob.image_batch_id')
                    ->join('generations as cg', 'cg.id', '=', 'cb.generation_id')
                    ->join('generation_outputs as co', 'co.id', '=', 'cb.output_id')
                    ->whereColumn('ob.output_id', 'generation_outputs.id')
                    ->where('ob.position', '>', 1)
                    ->where('cg.kind', Generation::KIND_OUTFIT_COVER)
                    ->where('cg.product_id', '>', 0)
                    ->whereIn('co.status', $pending))
                // Lô KHÔNG dùng sản phẩm (2026-11-16, người dùng chốt): ảnh outfit ĐẦU TIÊN (vị trí 2) làm TRƯỚC — worker viết
                // hook = tiêu đề lô rồi vẽ; ảnh bìa = chính ảnh đó + hook theo mẫu bìa. Bìa chờ ảnh vị trí 2 xong / lỗi.
                ->whereNotExists(fn ($q) => $this->firstOutfitPending($q, $pending)->where('ob.position', 1))
                // Ảnh outfit vị trí 3+ chờ tới khi lô có tiêu đề, hoặc ảnh vị trí 2 và ảnh bìa đều không còn chờ
                // (ảnh vị trí 2 lỗi -> bìa tự viết hook như cũ, ảnh sau dùng tiêu đề của bìa)
                ->whereNotExists(fn ($q) => $q->from('image_batch_images as ob')
                    ->join('image_batches as b', 'b.id', '=', 'ob.image_batch_id')
                    ->join('generations as og', 'og.id', '=', 'ob.generation_id')
                    ->join('image_batch_images as pb', 'pb.image_batch_id', '=', 'ob.image_batch_id')
                    ->join('generation_outputs as po', 'po.id', '=', 'pb.output_id')
                    ->whereColumn('ob.output_id', 'generation_outputs.id')
                    ->where('ob.position', '>', 2)
                    ->where('og.product_id', 0)
                    ->whereIn('og.kind', Generation::OUTFIT_KINDS)
                    ->whereNull('b.title')
                    ->whereIn('pb.position', [1, 2])
                    ->whereIn('po.status', $pending))
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if (! $output) {
                return null;
            }

            // Việc của ảnh (output queued luôn có 1 việc queued; dữ liệu cũ thiếu thì tạo bù)
            $job = ImageJob::query()->where('generation_output_id', $output->id)->where('status', WorkerJob::QUEUED)
                ->lockForUpdate()->first()
                ?? ImageJob::query()->create([
                    'type' => $worker->type === Worker::TYPE_OUTFIT ? WorkerJob::TYPE_OUTFIT : WorkerJob::TYPE_IMAGE,
                    'generation_output_id' => $output->id,
                    'status' => WorkerJob::QUEUED,
                ]);
            $job->update([
                'status' => WorkerJob::PROCESSING,
                'worker_id' => $worker->id,
                'attempts' => $job->attempts + 1,
                'lease_expires_at' => now()->addMinutes(config('ai90.worker.lease_minutes')),
                'claimed_at' => now(),
            ]);
            $output->update(['status' => OutputStatus::Processing]);

            Generation::query()
                ->whereKey($output->generation_id)
                ->where('status', GenerationStatus::Queued)
                ->update(['status' => GenerationStatus::Processing]);

            return $job->setRelation('output', $output);
        });
    }

    /** Ảnh của lô không sản phẩm mà ảnh outfit đầu tiên (vị trí 2) cùng lô còn đang chờ / đang tạo. */
    private function firstOutfitPending($q, array $pending)
    {
        return $q->from('image_batch_images as ob')
            ->join('generations as og', 'og.id', '=', 'ob.generation_id')
            ->join('image_batch_images as fb', 'fb.image_batch_id', '=', 'ob.image_batch_id')
            ->join('generation_outputs as fo', 'fo.id', '=', 'fb.output_id')
            ->whereColumn('ob.output_id', 'generation_outputs.id')
            ->where('og.product_id', 0)
            ->whereIn('og.kind', Generation::OUTFIT_KINDS)
            ->where('fb.position', 2)
            ->whereIn('fo.status', $pending);
    }

    public function complete(Worker $worker, ImageJob $job, UploadedFile $image): void
    {
        $this->assertAssigned($worker, $job);
        $output = $job->output()->firstOrFail();

        // Lưu ảnh trước, ngoài transaction, để không giữ khóa trong lúc upload lên S3.
        $directory = MediaStorage::datedDirectory('generations/outputs');
        $path = $this->media->storeUpload($image, $directory);
        $thumbPath = $this->storeThumb($image, $directory);

        try {
            DB::transaction(function () use ($worker, $job, $output, $path, $thumbPath, $image) {
                $this->assertAssigned($worker, $this->lockJob($job));
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

    /**
     * Gia hạn job đang làm thêm lease_minutes tính từ bây giờ (worker phối đồ gọi định kỳ vì một ảnh có thể làm lâu hơn
     * lease_minutes). Job không còn được giao cho worker này -> 409 JOB_NOT_ASSIGNED.
     */
    public function extend(Worker $worker, ImageJob $job): ImageJob
    {
        return DB::transaction(function () use ($worker, $job) {
            $locked = $this->lockJob($job);
            $this->assertAssigned($worker, $locked);
            $locked->update(['lease_expires_at' => now()->addMinutes(config('ai90.worker.lease_minutes'))]);

            return $locked;
        });
    }

    public function fail(Worker $worker, ImageJob $job, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $job, $errorCode, $errorMessage) {
            $this->assertAssigned($worker, $this->lockJob($job));
            $this->finalizer->fail($job->generation_output_id, $errorCode, $errorMessage ?: 'Worker không tạo được ảnh.');
        });
    }

    /**
     * Ảnh worker giữ quá hạn: còn lượt thì trả về hàng đợi, hết lượt thì thất bại.
     *
     * @return array{requeued: int, failed: int}
     */
    public function releaseExpiredLeases(): array
    {
        $expired = ImageJob::query()
            ->where('status', WorkerJob::PROCESSING)
            ->where('lease_expires_at', '<', now())
            ->get(['id', 'generation_output_id', 'attempts']);

        $requeued = 0;
        $failed = 0;
        foreach ($expired as $job) {
            if ($job->attempts >= config('ai90.worker.max_attempts')) {
                $this->finalizer->fail($job->generation_output_id, 'TIMEOUT', 'Worker không trả kết quả sau '.$job->attempts.' lần nhận.');
                $failed++;

                continue;
            }

            // Điều kiện trong WHERE: worker vừa trả kết quả thì không bị giao lại.
            $done = DB::transaction(function () use ($job) {
                $n = ImageJob::query()->whereKey($job->id)->where('status', WorkerJob::PROCESSING)->where('lease_expires_at', '<', now())
                    ->update(['status' => WorkerJob::QUEUED, 'worker_id' => null, 'lease_expires_at' => null]);
                if ($n) {
                    GenerationOutput::query()->whereKey($job->generation_output_id)->where('status', OutputStatus::Processing)
                        ->update(['status' => OutputStatus::Queued]);
                }

                return $n;
            });
            $requeued += $done;
        }

        return ['requeued' => $requeued, 'failed' => $failed];
    }

    private function lockJob(ImageJob $job): ImageJob
    {
        return ImageJob::query()->lockForUpdate()->findOrFail($job->id);
    }

    /** Chỉ worker đang giữ việc mới được trả kết quả (việc hết hạn đã giao cho worker khác thì từ chối). */
    private function assertAssigned(Worker $worker, ImageJob $job): void
    {
        if ($job->status !== WorkerJob::PROCESSING || $job->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }
    }
}
