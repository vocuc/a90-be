<?php

namespace App\Services\Channel;

use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\ChannelTextTemplate;
use App\Models\ContentJob;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Models\Worker;
use App\Services\MediaStorage;
use App\Support\PostSamples;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dựng nội dung bài ảnh bằng CONTENT-WORKER (a90-fe note/xay-kenh-tiktok/worker-content.txt, chốt 2026-11-21).
 * A51 KHÔNG dựng ảnh: chỉ giao nguyên liệu và nhận ảnh hoàn chỉnh.
 * - Bài ảnh có chữ hoặc dạng bài cần dựng (bìa ghép top_n): 1 job / bài, giao content-worker đầu tiên gắn với shop;
 *   bài ở generating ("Đang chuẩn bị ảnh") tới khi xong.
 * - Worker gửi ảnh hoàn chỉnh THEO THỨ TỰ SLIDE (files[0..]); số ảnh = số nguyên liệu, riêng dạng có bìa ghép = +1
 *   (ảnh đầu là bìa, A51 thêm thành slide 1). Mọi ảnh cùng cỡ (TikTok crop 3:4 nếu lệch).
 * - Xong -> bài về hàng chờ đăng (queued). Lỗi -> pending_review + last_error CONTENT_FAILED.
 * - Sửa chữ / làm lại -> job cũ superseded (worker cũ gửi kết quả -> 409), tạo job mới.
 */
class ContentJobService
{
    public const FAILED_CODE = 'CONTENT_FAILED';

    public const NO_WORKER_CODE = 'CONTENT_WORKER_REQUIRED';

    public function __construct(private MediaStorage $media) {}

    /** Shop có content-worker để dựng bài ảnh không (lập kế hoạch kiểm tra trước). */
    public static function hasWorker(int $userId): bool
    {
        return Worker::query()->assignedTo($userId, Worker::TYPE_CONTENT)->exists();
    }

    /** Bài ảnh cần content-worker dựng: có chữ trên ảnh hoặc dạng bài có bìa ghép. */
    public static function needsBuild(?string $sampleKey, bool $hasText): bool
    {
        return $hasText || (bool) (PostSamples::get((string) $sampleKey)['cover_collage'] ?? false);
    }

    /**
     * Giao (lại) việc dựng nội dung cho bài. Bài không cần dựng: chỉ bỏ job cũ, trả false.
     * Gọi trong transaction; bài phải đã có platforms đã queue.
     */
    public function queueForPost(SocialPost $post): bool
    {
        $post->contentJobs()->whereIn('status', [ContentJob::QUEUED, ContentJob::PROCESSING])
            ->update(['status' => ContentJob::SUPERSEDED, 'finished_at' => now()]);
        $this->removeWorkerSlides($post);

        $images = $post->images()->orderBy('position')->get();
        $hasText = $images->contains(fn (SocialPostImage $i) => trim((string) $i->overlay_text) !== '');
        $needs = ! $post->isVideo() && $images->isNotEmpty() && self::needsBuild($post->sample_key, $hasText);
        foreach ($images as $image) {
            $image->update(['overlay_path' => null, 'overlay_status' => $needs ? 'pending' : null]);
        }
        if (! $needs) {
            return false;
        }

        $workerId = Worker::query()->assignedTo($post->user_id, Worker::TYPE_CONTENT)->orderBy('id')->value('id');
        if (! $workerId) {
            $post->status = SocialPostStatus::PendingReview;
            $post->last_error = ['code' => self::NO_WORKER_CODE, 'message' => 'Shop chưa được gắn worker dựng nội dung.'];
            $post->save();

            return true;
        }
        $post->contentJobs()->create(['user_id' => $post->user_id, 'worker_id' => $workerId, 'sample_key' => $post->sample_key, 'status' => ContentJob::QUEUED]);
        $post->status = SocialPostStatus::Generating;
        $post->last_error = null;
        $post->save();

        return true;
    }

    /** Worker nhận 1 job của mình (cũ nhất trước). Job nhận rồi im lặng quá LEASE_MINUTES được giao lại. */
    public function claim(Worker $worker): ?ContentJob
    {
        return DB::transaction(function () use ($worker) {
            $job = ContentJob::query()
                ->where('worker_id', $worker->id)
                ->where(fn ($q) => $q->where('status', ContentJob::QUEUED)
                    ->orWhere(fn ($q) => $q->where('status', ContentJob::PROCESSING)
                        ->where('claimed_at', '<', now()->subMinutes(ContentJob::LEASE_MINUTES))))
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();
            if (! $job) {
                return null;
            }
            $job->update(['status' => ContentJob::PROCESSING, 'claimed_at' => now(), 'attempts' => $job->attempts + 1,
                'lease_expires_at' => now()->addMinutes(ContentJob::LEASE_MINUTES)]);

            return $job;
        });
    }

    /**
     * NGUYÊN LIỆU gửi worker: dạng bài, hook, ảnh gốc theo thứ tự slide (+ chữ, template chữ, loại ảnh, sản phẩm).
     * Link ảnh ký số tạo lúc nhận.
     */
    public function request(ContentJob $job): array
    {
        $post = $job->post()->with(['images.output.generation.product'])->firstOrFail();
        $templates = ChannelTextTemplate::query()
            ->whereIn('id', $post->images->pluck('text_template_id')->filter()->unique())
            ->get(['id', 'name', 'style'])
            ->keyBy('id');
        $sample = PostSamples::get((string) $post->sample_key);

        return [
            'post_id' => $post->id,
            'sample_key' => $job->sample_key ?? $post->sample_key,
            'sample_name' => $sample['name'] ?? null,
            // true: worker phải gửi thêm 1 ảnh bìa ghép ở ĐẦU (số ảnh gửi = số nguyên liệu + 1)
            'cover_collage' => (bool) ($sample['cover_collage'] ?? false),
            'hook' => $post->hook,
            'images' => $post->images->sortBy('position')->values()->map(function (SocialPostImage $i) use ($templates) {
                $generation = $i->output?->generation;
                $template = $templates->get($i->text_template_id);

                return [
                    'image_id' => $i->id,
                    'url' => $this->media->url($i->imagePath()),
                    'kind' => $generation ? ($generation->isOutfit() ? 'outfit' : 'product') : 'upload',
                    'product' => $generation?->product ? ['id' => $generation->product->id, 'name' => $generation->product->name] : null,
                    'text' => trim((string) $i->overlay_text) ?: null,
                    'text_template' => $template ? ['id' => $template->id, 'name' => $template->name, 'style' => $template->style] : null,
                ];
            })->all(),
        ];
    }

    /**
     * Worker gửi ảnh HOÀN CHỈNH theo thứ tự slide. Đủ số ảnh + cùng cỡ -> lưu, bài về hàng chờ đăng.
     *
     * @param  array<int, UploadedFile>  $files  files[0..n-1]
     */
    public function complete(Worker $worker, int $jobId, array $files): void
    {
        $job = $this->assigned($worker, $jobId);
        $post = $job->post()->firstOrFail();
        $images = $post->images()->orderBy('position')->get();
        $cover = (bool) (PostSamples::get((string) $post->sample_key)['cover_collage'] ?? false);
        $need = $images->count() + ($cover ? 1 : 0);
        ksort($files);
        $files = array_values($files);
        if (count($files) !== $need) {
            throw new BusinessException('CONTENT_FILES_COUNT', "Cần đúng {$need} ảnh theo thứ tự slide".($cover ? ' (bìa ghép ở đầu)' : '').', nhận '.count($files).'.', 422);
        }
        $sizes = array_map(fn (UploadedFile $f) => @getimagesize($f->getRealPath()) ?: [0, 0], $files);
        foreach ($sizes as $i => $size) {
            if ($size[0] !== $sizes[0][0] || $size[1] !== $sizes[0][1]) {
                throw new BusinessException('CONTENT_SIZE_MISMATCH', 'Ảnh #'.($i + 1)." cỡ {$size[0]}x{$size[1]} khác ảnh đầu {$sizes[0][0]}x{$sizes[0][1]} (TikTok sẽ cắt 3:4).", 422);
            }
        }

        // Lưu file trước (ngoài transaction), sau đó mới đổi trạng thái
        $paths = array_map(fn (UploadedFile $f) => $this->media->storeUpload($f, "content/{$post->id}"), $files);

        DB::transaction(function () use ($worker, $jobId, $paths, $cover, $images, $post) {
            $job = $this->assigned($worker, $jobId, lock: true);
            if ($cover) {
                // Bìa ghép: thêm thành slide 1 (ảnh upload, đánh dấu worker thêm để làm lại thì bỏ)
                SocialPostImage::query()->where('social_post_id', $post->id)->increment('position');
                $post->images()->create(['image_path' => $paths[0], 'position' => 1, 'added_by_worker' => true,
                    'overlay_path' => $paths[0], 'overlay_status' => 'done']);
                array_shift($paths);
            }
            foreach ($images->values() as $i => $image) {
                SocialPostImage::query()->whereKey($image->id)->update(['overlay_path' => $paths[$i], 'overlay_status' => 'done']);
            }
            $job->update(['status' => ContentJob::COMPLETED, 'finished_at' => now(), 'error_code' => null, 'error_message' => null]);

            $post = SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id);
            if ($post->status === SocialPostStatus::Generating) {
                $post->syncStatus();
                $post->save();
            }
        });
    }

    public function fail(Worker $worker, int $jobId, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $jobId, $errorCode, $errorMessage) {
            $job = $this->assigned($worker, $jobId, lock: true);
            $job->update([
                'status' => ContentJob::FAILED,
                'finished_at' => now(),
                'error_code' => Str::limit($errorCode, 50, ''),
                'error_message' => $errorMessage ? Str::limit($errorMessage, 490) : null,
            ]);
            $post = SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id);
            $post->images()->where('overlay_status', 'pending')->update(['overlay_status' => 'failed']);
            if ($post->status === SocialPostStatus::Generating) {
                $post->status = SocialPostStatus::PendingReview;
                $post->last_error = ['code' => self::FAILED_CODE, 'message' => 'Dựng nội dung lỗi ('.$errorCode.'), sửa chữ hoặc bấm "Dựng lại".'];
                $post->save();
            }
        });
    }

    /** Bỏ các slide worker đã thêm (bìa ghép cũ) rồi đánh số lại vị trí 1..n. */
    private function removeWorkerSlides(SocialPost $post): void
    {
        if (! $post->images()->where('added_by_worker', true)->exists()) {
            return;
        }
        $post->images()->where('added_by_worker', true)->delete();
        foreach ($post->images()->orderBy('position')->get() as $n => $image) {
            $image->update(['position' => $n + 1]);
        }
    }

    /** Job đang processing được giao cho đúng worker; job đã bị thay (sửa chữ / làm lại) -> 409. */
    private function assigned(Worker $worker, int $jobId, bool $lock = false): ContentJob
    {
        $job = ContentJob::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($jobId);
        if (! $job || $job->worker_id !== $worker->id || $job->status !== ContentJob::PROCESSING) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }
}
