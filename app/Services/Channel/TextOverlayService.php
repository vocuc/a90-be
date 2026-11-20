<?php

namespace App\Services\Channel;

use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\ChannelTextTemplate;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Models\TextOverlayJob;
use App\Models\Worker;
use App\Services\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chèn chữ lên ảnh bài đăng (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 5.9 - 5.11).
 * - Bài ảnh có chữ: 1 job / bài, giao worker đăng bài của kênh; bài ở generating ("Đang chuẩn bị ảnh") tới khi xong.
 * - Worker nhận qua GET /api/v1/post-jobs (ưu tiên trước bài đăng, không cần tới giờ), gửi ảnh đã chèn từng ảnh.
 * - Xong -> bài về hàng chờ đăng (queued). Lỗi -> pending_review + last_error OVERLAY_FAILED.
 * - Sửa chữ -> job cũ superseded (worker cũ gửi kết quả -> 409), tạo job mới.
 */
class TextOverlayService
{
    public const FAILED_CODE = 'OVERLAY_FAILED';

    public function __construct(private MediaStorage $media) {}

    /**
     * Giao (lại) việc chèn chữ cho bài. Bài không còn ảnh nào có chữ: chỉ bỏ job cũ, trả false.
     * Gọi trong transaction; bài phải đã có worker_id + platforms đã queue.
     */
    public function queueForPost(SocialPost $post): bool
    {
        $post->overlayJobs()->whereIn('status', [TextOverlayJob::QUEUED, TextOverlayJob::PROCESSING])
            ->update(['status' => TextOverlayJob::SUPERSEDED, 'finished_at' => now()]);

        $images = $post->images()->get();
        $withText = $images->filter(fn (SocialPostImage $i) => trim((string) $i->overlay_text) !== '');
        foreach ($images as $image) {
            $hasText = trim((string) $image->overlay_text) !== '';
            $image->update(['overlay_path' => null, 'overlay_status' => $hasText ? 'pending' : null]);
        }
        if ($withText->isEmpty()) {
            return false;
        }

        $post->overlayJobs()->create(['worker_id' => $post->worker_id, 'status' => TextOverlayJob::QUEUED]);
        $post->status = SocialPostStatus::Generating;
        $post->last_error = null;
        $post->save();

        return true;
    }

    /** Worker nhận 1 job chèn chữ của mình (cũ nhất trước). Job nhận rồi im lặng quá LEASE_MINUTES được giao lại. */
    public function claim(Worker $worker): ?TextOverlayJob
    {
        return DB::transaction(function () use ($worker) {
            $job = TextOverlayJob::query()
                ->where('worker_id', $worker->id)
                ->where(fn ($q) => $q->where('status', TextOverlayJob::QUEUED)
                    ->orWhere(fn ($q) => $q->where('status', TextOverlayJob::PROCESSING)
                        ->where('claimed_at', '<', now()->subMinutes(TextOverlayJob::LEASE_MINUTES))))
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();
            if (! $job) {
                return null;
            }
            $job->update(['status' => TextOverlayJob::PROCESSING, 'claimed_at' => now()]);

            return $job;
        });
    }

    /** Dữ liệu gửi worker: các ảnh có chữ + style template. Link ảnh gốc ký số tạo lúc nhận. */
    public function request(TextOverlayJob $job): array
    {
        $post = $job->post()->with(['images.output'])->firstOrFail();
        $templates = ChannelTextTemplate::query()
            ->whereIn('id', $post->images->pluck('text_template_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return [
            'post_id' => $post->id,
            'images' => $post->images
                ->filter(fn (SocialPostImage $i) => trim((string) $i->overlay_text) !== '')
                ->map(fn (SocialPostImage $i) => [
                    'image_id' => $i->id,
                    'image_url' => $this->media->url($i->imagePath()),
                    'text' => $i->overlay_text,
                    'style' => $templates->get($i->text_template_id)?->style,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Worker gửi ảnh đã chèn chữ: $files[image_id] cho mọi ảnh có chữ của bài. Xong -> bài về hàng chờ đăng.
     *
     * @param  array<int|string, UploadedFile>  $files
     */
    public function complete(Worker $worker, int $jobId, array $files): void
    {
        $job = $this->assigned($worker, $jobId);
        $post = $job->post()->with('images')->firstOrFail();
        $needed = $post->images->filter(fn (SocialPostImage $i) => trim((string) $i->overlay_text) !== '');
        $missing = $needed->reject(fn (SocialPostImage $i) => isset($files[$i->id]));
        if ($missing->isNotEmpty()) {
            throw new BusinessException('OVERLAY_FILES_MISSING', 'Thiếu ảnh đã chèn chữ cho image_id: '.$missing->pluck('id')->implode(', '), 422);
        }

        // Lưu file trước (ngoài transaction), sau đó mới đổi trạng thái
        $paths = [];
        foreach ($needed as $image) {
            $paths[$image->id] = $this->media->storeUpload($files[$image->id], "overlays/{$post->id}");
        }

        DB::transaction(function () use ($worker, $jobId, $paths) {
            $job = $this->assigned($worker, $jobId, lock: true);
            foreach ($paths as $imageId => $path) {
                SocialPostImage::query()->whereKey($imageId)->update(['overlay_path' => $path, 'overlay_status' => 'done']);
            }
            $job->update(['status' => TextOverlayJob::COMPLETED, 'finished_at' => now(), 'error_code' => null, 'error_message' => null]);

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
                'status' => TextOverlayJob::FAILED,
                'finished_at' => now(),
                'error_code' => Str::limit($errorCode, 50, ''),
                'error_message' => $errorMessage ? Str::limit($errorMessage, 490) : null,
            ]);
            $post = SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id);
            $post->images()->where('overlay_status', 'pending')->update(['overlay_status' => 'failed']);
            if ($post->status === SocialPostStatus::Generating) {
                $post->status = SocialPostStatus::PendingReview;
                $post->last_error = ['code' => self::FAILED_CODE, 'message' => 'Chèn chữ lên ảnh lỗi, sửa chữ hoặc bấm "Chèn lại".'];
                $post->save();
            }
        });
    }

    /** Job đang processing được giao cho đúng worker; job đã bị thay (chủ shop sửa chữ) -> 409. */
    private function assigned(Worker $worker, int $jobId, bool $lock = false): TextOverlayJob
    {
        $job = TextOverlayJob::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($jobId);
        if (! $job || $job->worker_id !== $worker->id || $job->status !== TextOverlayJob::PROCESSING) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }
}
