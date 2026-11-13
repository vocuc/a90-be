<?php

namespace App\Services\Social;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use App\Models\SocialPost;
use App\Models\SocialPublishJob;
use App\Models\User;
use App\Models\Worker;
use App\Services\AuditLogger;
use App\Services\MediaStorage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Đăng bài ảnh + video qua worker (note/gop-luong-dang-bai.txt ở repo frontend).
 * - Chủ shop bấm "Đăng bài" ở trang bài: chọn worker, loại bài (ảnh / video + template), nền tảng (một hoặc nhiều),
 *   giờ đăng (mặc định bây giờ). Bài sang queued: chờ tới giờ, còn sửa / xoá được.
 * - Worker (đúng worker được chọn) nhận bài đã tới giờ: 1 job / bài chứa mọi nền tảng đang chờ, các nền tảng sang
 *   publishing. Worker tự lo đăng bằng tài khoản nào, xong báo 1 lần: completed -> các nền tảng published; failed ->
 *   failed, bài về pending_review kèm last_error (chủ shop sửa rồi đăng lại).
 * - Job không có hạn: worker im lặng thì bài giữ "Đang đăng", chủ shop bấm "Tạo lại" (có thể đăng trùng).
 */
class SocialPublishJobService
{
    public function __construct(
        private SocialPostService $posts,
        private VideoPostService $videos,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /**
     * "Đăng bài": lưu toàn bộ chỉnh sửa + worker + nền tảng + giờ đăng, bài sang queued. Bài chờ gửi (pending_review) hoặc
     * đang chờ tới giờ (queued, worker chưa nhận). type video: đổi bài thành bài video (template + đúng số ảnh template cần).
     *
     * @param  array{worker_id: int, type: string, video_template_id?: ?int, product_id?: ?int, platforms: string[], scheduled_at: CarbonInterface, images: array<int, array>, title?: ?string, content?: ?string, hashtags?: ?array}  $data
     */
    public function publish(User $user, SocialPost $post, array $data): SocialPost
    {
        $this->assertEditable($post);

        $worker = $this->findWorker($user, (int) $data['worker_id']);
        $video = $data['type'] === SocialPost::TYPE_VIDEO;
        $template = $video ? $this->videos->resolveTemplate($user, (int) ($data['video_template_id'] ?? 0), $post->video_template_id) : null;
        $productId = $video ? $this->videos->resolveProductId($user, $data['product_id'] ?? null) : null;
        $platforms = $this->resolvePlatforms($post, $data['platforms']);
        $hashtags = SocialPost::normalizeHashtags($data['hashtags'] ?? []);
        $images = $this->resolveImages($user, $post, $data['images']);
        if ($template && count($images) !== $template->image_count) {
            throw ValidationException::withMessages([
                'images' => "Template \"{$template->name}\" cần đúng {$template->image_count} ảnh, bài đang có ".count($images).' ảnh.',
            ]);
        }
        $this->assertWithinLimits($platforms, SocialPost::description($data['content'] ?? null, $hashtags), $video ? 0 : count($images));

        $post = DB::transaction(function () use ($user, $post, $data, $hashtags, $images, $platforms, $worker, $template, $productId) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            // Bấm đăng 2 lần / worker vừa nhận: 409, không ghi đè
            $this->assertEditable($post);

            $post->fill([
                'type' => $template ? SocialPost::TYPE_VIDEO : SocialPost::TYPE_PHOTO,
                'worker_id' => $worker->id,
                ...VideoPostService::templateSnapshot($template),
                'product_id' => $productId,
                'scheduled_at' => $data['scheduled_at'],
                'title' => trim((string) ($data['title'] ?? '')),
                'content' => $data['content'] ?? '',
                'hashtags' => $hashtags,
                'platforms' => array_map(fn (SocialPlatform $p) => $p->value, $platforms),
                'last_error' => null,
            ]);
            $this->syncImages($post, $images);
            // Job lỗi lần trước: không tính vào lịch sử hiện tại
            $post->publishJobs()->where('status', OutputStatus::Failed)->update(['superseded' => true]);
            $post->queuePlatforms();
            $post->syncStatus();
            $post->save();

            $this->audit->log('social_post.publish', $post, [
                'type' => $post->type, 'worker_id' => $worker->id, 'platforms' => $post->platforms, 'images' => count($images),
            ], $user->id);

            return $post;
        });

        return $post->refresh();
    }

    /**
     * "Đăng lại" (bài lỗi: các nền tảng đã chọn chưa đăng xong về chờ) / "Tạo lại" (worker nhận rồi im lặng: job đang chạy
     * bị bỏ, worker cũ báo kết quả sau đó -> 409 JOB_NOT_ASSIGNED; chủ shop tự chịu rủi ro đăng trùng). Bài về queued.
     */
    public function retry(User $user, SocialPost $post): SocialPost
    {
        return DB::transaction(function () use ($user, $post) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            if (! in_array($post->status, [SocialPostStatus::PendingReview, SocialPostStatus::Publishing], true)) {
                throw new BusinessException('POST_NOT_RETRYABLE', match ($post->status) {
                    SocialPostStatus::Published => 'Bài đã đăng xong.',
                    SocialPostStatus::Generating => 'Bài đang được tạo nội dung.',
                    default => 'Bài đang chờ tới giờ đăng.',
                }, 409);
            }
            if (! $post->worker_id || ! $post->platforms) {
                throw new BusinessException('POST_NOT_RETRYABLE', 'Bài chưa chọn worker / nền tảng, hãy bấm Đăng bài.', 409);
            }

            $post->publishJobs()->whereIn('status', [OutputStatus::Processing, OutputStatus::Failed])->where('superseded', false)->get()
                ->each(fn (SocialPublishJob $job) => $job->update([
                    'superseded' => true,
                    ...($job->status === OutputStatus::Processing ? [
                        'status' => OutputStatus::Failed,
                        'error_code' => 'RETRIED',
                        'error_message' => 'Chủ shop bấm tạo lại.',
                        'finished_at' => now(),
                    ] : []),
                ]));
            $post->queuePlatforms();
            $post->last_error = null;
            $post->syncStatus();
            $post->save();

            $this->audit->log('social_post.retry', $post, [], $user->id);

            return $post;
        });
    }

    /**
     * Worker nhận bài (ảnh hoặc video) đã tới giờ được giao cho nó, cũ nhất trước: 1 job chứa mọi nền tảng đang chờ của
     * bài, các nền tảng sang publishing. Không đặt hạn. SKIP LOCKED để gọi song song không nhận trùng.
     */
    public function claim(Worker $worker): ?SocialPublishJob
    {
        $worker->assertType(Worker::TYPE_PUBLISH);

        return DB::transaction(function () use ($worker) {
            $post = SocialPost::query()
                ->where('status', SocialPostStatus::Queued)
                ->where('worker_id', $worker->id)
                ->where('scheduled_at', '<=', now())
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();
            if (! $post) {
                return null;
            }

            $platforms = array_keys(array_filter($post->platformStatuses(), fn ($status) => $status === PlatformPublishStatus::Queued));
            $job = $post->publishJobs()->create([
                'platforms' => $platforms,
                'status' => OutputStatus::Processing,
                'worker_id' => $worker->id,
            ]);
            foreach ($platforms as $platform) {
                $post->{SocialPlatform::from($platform)->statusColumn()} = PlatformPublishStatus::Publishing;
            }
            $post->syncStatus();
            $post->save();

            return $job;
        });
    }

    /** Dữ liệu gửi worker. Link ảnh ký số tạo lúc nhận job (bài có thể đã chờ lâu). */
    public function request(SocialPublishJob $job): array
    {
        $post = $job->post()->with(['images.output', 'product'])->firstOrFail();
        $hashtags = $post->hashtags ?? [];

        return [
            'post_id' => $post->id,
            'type' => $post->type,
            'platforms' => $job->platforms,
            'title' => $post->title,
            'content' => (string) $post->content,
            'hashtags' => $hashtags,
            // Nội dung + dòng hashtag, điền thẳng vào ô nội dung của nền tảng
            'description' => SocialPost::description($post->content, $hashtags),
            // Theo thứ tự, ảnh đầu là ảnh bìa. Ảnh người dùng đã tắt thì bỏ qua.
            'images' => $post->images
                ->filter(fn ($image) => $image->isUsable())
                ->map(fn ($image) => $this->media->url($image->imagePath()))
                ->values()
                ->all(),
            // Bài video: template để dựng video (khớp theo tên với template Remotion). Bài ảnh: null
            'template' => $post->isVideo() ? [
                'id' => $post->video_template_id,
                'name' => $post->video_template_name,
                'video_url' => $post->video_template_url,
                'image_count' => $post->video_image_count,
            ] : null,
            'product' => $post->product ? ['id' => $post->product->id, 'name' => $post->product->name] : null,
            'scheduled_at' => $post->scheduled_at,
        ];
    }

    /** Worker đã đăng xong mọi nền tảng của job. post_url: link bài nếu worker lấy được. */
    public function complete(Worker $worker, SocialPublishJob $job, ?string $postUrl = null): void
    {
        DB::transaction(function () use ($worker, $job, $postUrl) {
            $job = $this->lockAssigned($worker, $job);
            $job->update(['status' => OutputStatus::Completed, 'post_url' => $postUrl, 'finished_at' => now()]);
            $this->finishPlatforms($job, PlatformPublishStatus::Published);
        });
    }

    /** Worker báo lỗi: không giao lại, các nền tảng của job sang failed. Mã lỗi chi tiết chỉ lưu ở job cho admin. */
    public function fail(Worker $worker, SocialPublishJob $job, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $job, $errorCode, $errorMessage) {
            $job = $this->lockAssigned($worker, $job);
            $job->update([
                'status' => OutputStatus::Failed,
                'finished_at' => now(),
                'error_code' => $errorCode,
                'error_message' => Str::limit($errorMessage ?: 'Worker không đăng được bài.', 490),
            ]);
            $this->finishPlatforms($job, PlatformPublishStatus::Failed);
        });
    }

    /** Worker đang bật được gắn cho shop; không thì 422 (chọn worker ở thanh tiêu đề / popup). */
    public function findWorker(User $user, int $workerId): Worker
    {
        return $this->videos->findWorker($user, $workerId);
    }

    private function assertEditable(SocialPost $post): void
    {
        if ($post->status === SocialPostStatus::AwaitingApproval) {
            throw BusinessException::planPostNotApproved();
        }
        if (! $post->isEditable()) {
            throw new BusinessException('POST_NOT_EDITABLE', match ($post->status) {
                SocialPostStatus::Generating => 'AI đang viết nội dung, vui lòng chờ xong rồi đăng.',
                SocialPostStatus::Publishing => 'Worker đã nhận bài, không sửa được nữa.',
                default => 'Bài đã đăng xong, không sửa được.',
            }, 409);
        }
    }

    /** Các nền tảng của job đang publishing -> published / failed, tính lại trạng thái bài. Gọi trong transaction. */
    private function finishPlatforms(SocialPublishJob $job, PlatformPublishStatus $status): void
    {
        $post = SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id);
        foreach ($job->platformEnums() as $platform) {
            if ($post->platformStatus($platform) === PlatformPublishStatus::Publishing) {
                $post->{$platform->statusColumn()} = $status;
            }
        }
        $post->syncStatus();
        $post->save();
    }

    private function lockAssigned(Worker $worker, SocialPublishJob $job): SocialPublishJob
    {
        $job = SocialPublishJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($job->status !== OutputStatus::Processing || $job->worker_id !== $worker->id || $job->superseded) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }

    /**
     * Nền tảng chủ shop chọn (bắt buộc, một hoặc nhiều). Nền tảng đã đăng xong không đăng lại (tránh bài trùng).
     *
     * @param  string[]  $requested
     * @return SocialPlatform[]
     */
    private function resolvePlatforms(SocialPost $post, array $requested): array
    {
        $platforms = array_map(fn ($value) => SocialPlatform::from($value), array_values(array_unique($requested)));
        $done = array_filter($platforms, fn (SocialPlatform $platform) => $post->platformStatus($platform) === PlatformPublishStatus::Published);
        if ($done) {
            throw ValidationException::withMessages([
                'platforms' => 'Bài đã đăng lên '.implode(', ', array_map(fn ($p) => $p->label(), $done)).', không đăng lại.',
            ]);
        }

        return $platforms;
    }

    /**
     * Giới hạn riêng từng nền tảng. imageCount 0: không kiểm tra số ảnh (bài video).
     *
     * @param  SocialPlatform[]  $platforms
     */
    private function assertWithinLimits(array $platforms, string $description, int $imageCount): void
    {
        $errors = [];
        foreach ($platforms as $platform) {
            if (! isset($errors['content']) && mb_strlen($description) > $platform->descriptionMax()) {
                $errors['content'] = "{$platform->label()}: nội dung kèm hashtag tối đa {$platform->descriptionMax()} ký tự.";
            }
            if (! isset($errors['images']) && $imageCount > $platform->maxImages()) {
                $errors['images'] = "{$platform->label()}: tối đa {$platform->maxImages()} ảnh mỗi bài.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Thứ tự cuối cùng: { image_id } (ảnh đang có trong bài) hoặc { generation_id, output_id } (ảnh có sẵn vừa thêm).
     * Ảnh upload thẳng (lookbook) chỉ có thể là { image_id }, output = null.
     *
     * @return array<int, array{image_id: ?int, output: ?GenerationOutput}> theo thứ tự (image_id null: ảnh mới thêm)
     */
    private function resolveImages(User $user, SocialPost $post, array $items): array
    {
        $current = $post->images()->with('output')->get()->keyBy('id');
        $newRefs = [];
        $errors = [];
        $resolved = [];

        foreach ($items as $i => $item) {
            if (isset($item['image_id'])) {
                $image = $current->get((int) $item['image_id']);
                if (! $image) {
                    $errors["images.{$i}"] = 'Ảnh không còn trong bài đăng.';

                    continue;
                }
                // Ảnh người dùng đã tắt: bỏ qua (syncImages gỡ khỏi bài), không báo lỗi
                if ($image->output?->disabled_at) {
                    continue;
                }
                $resolved[$i] = ['image_id' => $image->id, 'output' => $image->output];
            } else {
                $newRefs[$i] = ['generation_id' => (int) $item['generation_id'], 'output_id' => (int) $item['output_id']];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($newRefs ? $this->posts->resolveOwnedOutputs($user, $newRefs, 'images') : [] as $i => $output) {
            $resolved[$i] = ['image_id' => null, 'output' => $output];
        }
        ksort($resolved);
        if (! $resolved) {
            throw ValidationException::withMessages(['images' => 'Các ảnh trong bài đã bị tắt, hãy chọn ảnh khác.']);
        }

        $keys = array_map(fn ($r) => $r['output'] ? 'output:'.$r['output']->id : 'image:'.$r['image_id'], $resolved);
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['images' => 'Có ảnh bị trùng trong bài đăng.']);
        }
        if (collect($resolved)->contains(fn ($r) => $r['output'] && $r['output']->status !== OutputStatus::Completed)) {
            throw new BusinessException('IMAGES_NOT_READY', 'Còn ảnh đang tạo hoặc bị lỗi. Hãy bỏ ảnh đó khỏi bài trước khi đăng.', 409);
        }

        return array_values($resolved);
    }

    /** @param  array<int, array{image_id: ?int, output: ?GenerationOutput}>  $images */
    private function syncImages(SocialPost $post, array $images): void
    {
        $keep = array_filter(array_column($images, 'image_id'));
        $post->images()->whereNotIn('id', $keep ?: [0])->delete();

        foreach ($images as $i => ['image_id' => $imageId, 'output' => $output]) {
            $imageId
                ? $post->images()->whereKey($imageId)->update(['position' => $i + 1])
                : $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
        }
    }
}
