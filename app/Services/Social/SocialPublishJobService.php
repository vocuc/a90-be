<?php

namespace App\Services\Social;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\PublishDriver;
use App\Enums\SocialPlatform;
use App\Exceptions\BusinessException;
use App\Jobs\PublishTikTokViaApi;
use App\Models\GenerationOutput;
use App\Models\PublishAccount;
use App\Models\SocialPost;
use App\Models\SocialPublishJob;
use App\Models\User;
use App\Models\Worker;
use App\Services\AuditLogger;
use App\Services\MediaStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Đăng bài lên TikTok / Facebook / Instagram (note/dang-bai-da-nen-tang.txt ở repo frontend): bấm đăng -> lưu chỉnh sửa,
 * tạo 1 job / nền tảng đã chọn, nền tảng sang queued.
 * - Worker: admin gắn tài khoản A51 với worker theo từng nền tảng (PublishAccount driver worker); worker chỉ nhận job của
 *   nền tảng và tài khoản A51 được gắn (publishing), đăng xong báo completed (published).
 * - API (chỉ TikTok, note/dang-tiktok-qua-api.txt): người dùng đã tự kết nối TikTok thì MẶC ĐỊNH đăng qua API, không qua
 *   worker. Job driver api do queue job PublishTikTokViaApi gửi (TikTokApiPublisher).
 * Lỗi / quá hạn không giao lại: nền tảng sang failed, xong hết thì bài về pending_review với thông báo chung, người dùng
 * bấm đăng lại các nền tảng lỗi. Job chờ worker không hết hạn.
 */
class SocialPublishJobService
{
    public function __construct(
        private SocialPostService $posts,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /**
     * Tài khoản đăng bài của người dùng, theo thứ tự nền tảng. Có cả dòng worker và dòng api của TikTok thì chỉ trả dòng
     * api (dòng sẽ được dùng khi đăng).
     *
     * @return Collection<int, PublishAccount>
     */
    public function accounts(User $user): Collection
    {
        $order = array_flip(SocialPlatform::values());

        return $user->publishAccounts()->get()
            ->sortBy(fn (PublishAccount $account) => [$order[$account->platform->value], $account->isApi() ? 0 : 1])
            ->unique(fn (PublishAccount $account) => $account->platform->value)
            ->values()
            ->toBase();
    }

    /**
     * @param  array{title?: ?string, content?: ?string, hashtags?: ?array, images: array<int, array>, platforms: string[]}  $data
     */
    public function publish(User $user, SocialPost $post, array $data): SocialPost
    {
        $this->posts->assertEditable($post);

        $platforms = $this->resolvePlatforms($post, $data['platforms']);
        $hashtags = SocialPost::normalizeHashtags($data['hashtags'] ?? []);
        $this->assertWithinLimits($platforms, SocialPost::description($data['content'] ?? null, $hashtags), count($data['images']));

        $images = $this->resolveImages($user, $post, $data['images']);

        // Đã tự kết nối TikTok: đăng qua API. Mất kết nối thì từ chối cả yêu cầu (không tự chuyển sang worker để
        // tránh đăng nhầm vào tài khoản của A51); người dùng kết nối lại rồi bấm đăng một lần.
        $tiktokApi = in_array(SocialPlatform::Tiktok, $platforms, true) ? $user->tiktokConnection : null;
        if ($tiktokApi && ! $tiktokApi->isUsable()) {
            throw BusinessException::tiktokNotConnected($tiktokApi->connectionReason() ?? 'expired');
        }

        $post = DB::transaction(function () use ($user, $post, $data, $hashtags, $images, $platforms, $tiktokApi) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            // Bấm đăng 2 lần: lần sau gặp bài đã queued -> 409, không tạo job thứ 2
            $this->posts->assertEditable($post);

            $post->fill([
                'title' => trim((string) ($data['title'] ?? '')),
                'content' => $data['content'] ?? '',
                'hashtags' => $hashtags,
                'last_error' => null,
            ]);
            $this->syncImages($post, $images);

            $jobs = [];
            foreach ($platforms as $platform) {
                $post->{$platform->statusColumn()} = PlatformPublishStatus::Queued;
                $api = $platform === SocialPlatform::Tiktok && $tiktokApi;
                $job = $post->publishJobs()->create([
                    'platform' => $platform,
                    'status' => OutputStatus::Queued,
                    'driver' => $api ? PublishDriver::Api : PublishDriver::Worker,
                    'publish_account_id' => $api ? $tiktokApi->id : null,
                ]);
                $jobs[$platform->value] = $job->id;
                if ($api) {
                    PublishTikTokViaApi::dispatch($job->id)->afterCommit();
                }
            }
            $post->syncStatus();
            $post->save();

            $this->audit->log('social_post.publish', $post, ['jobs' => $jobs, 'images' => count($images)], $user->id);

            return $post;
        });

        // Queue chạy đồng bộ (sync) có thể đã gửi xong job api: trả trạng thái mới nhất
        return $post->refresh();
    }

    /**
     * Job api bắt đầu gửi: queued -> processing (nền tảng publishing), hạn publish_timeout_minutes.
     * Queue thử lại khi TikTok tạm lỗi: job đang processing mà chưa có publish_id thì gửi tiếp.
     * Trả null khi job không còn cần gửi (đã xong / đã gửi / không phải job api).
     */
    public function startApiJob(int $jobId): ?SocialPublishJob
    {
        return DB::transaction(function () use ($jobId) {
            $job = SocialPublishJob::query()->lockForUpdate()->find($jobId);
            if (! $job || $job->driver !== PublishDriver::Api) {
                return null;
            }
            if ($job->status === OutputStatus::Processing && $job->external_id === null) {
                return $job;
            }
            if ($job->status !== OutputStatus::Queued) {
                return null;
            }

            $job->update([
                'status' => OutputStatus::Processing,
                'lease_expires_at' => now()->addMinutes(config('services.tiktok.publish_timeout_minutes')),
            ]);
            SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id)
                ->setPlatformStatus($job->platform, PlatformPublishStatus::Publishing);

            return $job;
        });
    }

    /** TikTok báo ảnh đã vào hộp thư: job completed, nền tảng published. Đã xong thì bỏ qua (webhook + status/fetch cùng báo). */
    public function completeApiJob(SocialPublishJob $job): void
    {
        DB::transaction(function () use ($job) {
            $job = SocialPublishJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($job->driver !== PublishDriver::Api || $job->status !== OutputStatus::Processing) {
                return;
            }
            $this->completeJob($job, null);
        });
    }

    /** Job api lỗi. error_message là thông báo tiếng Việt cho người dùng (SocialPostResource). */
    public function failApiJob(SocialPublishJob $job, string $errorCode, string $errorMessage): void
    {
        DB::transaction(function () use ($job, $errorCode, $errorMessage) {
            $job = SocialPublishJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($job->driver !== PublishDriver::Api || $job->status->isFinal()) {
                return;
            }
            $this->failJob($job, $errorCode, $errorMessage);
        });
    }

    /**
     * Nhận job chờ cũ nhất của nền tảng và tài khoản A51 được gắn với worker (dòng PublishAccount đang bật).
     * SKIP LOCKED để worker gọi lặp / song song không nhận trùng.
     */
    public function claim(Worker $worker): ?SocialPublishJob
    {
        $account = PublishAccount::query()->where('worker_id', $worker->id)->first();
        if (! $account) {
            throw new BusinessException('ACCOUNT_NOT_ASSIGNED', 'Worker chưa được gắn tài khoản đăng bài.', 409);
        }
        if (! $account->is_active || ! $account->user_id) {
            throw new BusinessException($account->platform->notAssignedCode(), 'Worker chưa được gắn tài khoản A51 hoặc đang bị tắt.', 409);
        }

        return DB::transaction(function () use ($worker, $account) {
            $job = SocialPublishJob::query()
                ->where('status', OutputStatus::Queued)
                ->where('driver', PublishDriver::Worker)
                ->where('platform', $account->platform)
                ->whereHas('post', fn ($q) => $q->where('user_id', $account->user_id))
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if (! $job) {
                return null;
            }

            $job->update([
                'status' => OutputStatus::Processing,
                'worker_id' => $worker->id,
                'publish_account_id' => $account->id,
                'lease_expires_at' => now()->addMinutes(config('ai90.worker.publish_lease_minutes')),
            ]);
            SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id)
                ->setPlatformStatus($job->platform, PlatformPublishStatus::Publishing);

            return $job;
        });
    }

    /** Dữ liệu gửi worker. Link ảnh ký số tạo lúc nhận job (job có thể đã chờ lâu). */
    public function request(SocialPublishJob $job): array
    {
        $post = $job->post()->with('images.output')->firstOrFail();
        $hashtags = $post->hashtags ?? [];

        return [
            'post_id' => $post->id,
            'platform' => $job->platform->value,
            'title' => $post->title,
            'content' => (string) $post->content,
            'hashtags' => $hashtags,
            // Nội dung + dòng hashtag, điền thẳng vào ô nội dung của nền tảng
            'description' => SocialPost::description($post->content, $hashtags),
            // Theo thứ tự, ảnh đầu là ảnh bìa
            'images' => $post->images->map(fn ($image) => $this->media->url($image->output->image_path))->values()->all(),
            // target_url: Facebook đăng vào trang / nhóm / trang cá nhân này
            'account' => $job->publishAccount?->only(['id', 'name', 'username', 'target_url']),
        ];
    }

    /** Worker đã đăng xong (TikTok: đã lưu nháp). post_url: link bài nếu worker lấy được. */
    public function complete(Worker $worker, SocialPublishJob $job, ?string $postUrl = null): void
    {
        DB::transaction(function () use ($worker, $job, $postUrl) {
            $this->completeJob($this->lockAssigned($worker, $job), $postUrl);
        });
    }

    /** Worker báo lỗi: không giao lại, nền tảng sang failed. Mã lỗi chi tiết chỉ lưu ở job cho admin. */
    public function fail(Worker $worker, SocialPublishJob $job, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $job, $errorCode, $errorMessage) {
            $this->failJob($this->lockAssigned($worker, $job), $errorCode, $errorMessage ?: 'Worker không đăng được bài.');
        });
    }

    /** Job worker giữ quá hạn: thất bại luôn (không giao lại vì có thể worker đã đăng một phần). */
    public function releaseExpiredLeases(): int
    {
        $ids = SocialPublishJob::query()
            ->where('status', OutputStatus::Processing)
            ->where('lease_expires_at', '<', now())
            ->pluck('id');

        $failed = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$failed) {
                $job = SocialPublishJob::query()->lockForUpdate()->find($id);
                if (! $job || $job->status !== OutputStatus::Processing || $job->lease_expires_at?->isFuture()) {
                    return;
                }
                $job->driver === PublishDriver::Api
                    ? $this->failJob($job, 'TIKTOK_TIMEOUT', 'TikTok xử lý quá lâu, vui lòng thử lại.')
                    : $this->failJob($job, 'WORKER_TIMEOUT', 'Worker không trả kết quả trong thời gian cho phép.');
                $failed++;
            });
        }

        return $failed;
    }

    private function completeJob(SocialPublishJob $job, ?string $postUrl): void
    {
        $job->update([
            'status' => OutputStatus::Completed,
            'post_url' => $postUrl,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ]);

        $post = SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id);
        if ($post->platformStatus($job->platform) === PlatformPublishStatus::Publishing) {
            $post->setPlatformStatus($job->platform, PlatformPublishStatus::Published);
        }
    }

    private function failJob(SocialPublishJob $job, string $errorCode, string $errorMessage): void
    {
        $job->update([
            'status' => OutputStatus::Failed,
            'lease_expires_at' => null,
            'finished_at' => now(),
            'error_code' => $errorCode,
            'error_message' => Str::limit($errorMessage, 490),
        ]);

        $post = SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id);
        if ($post->platformStatus($job->platform)?->isRunning()) {
            $post->setPlatformStatus($job->platform, PlatformPublishStatus::Failed);
        }
    }

    private function lockAssigned(Worker $worker, SocialPublishJob $job): SocialPublishJob
    {
        $job = SocialPublishJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($job->status !== OutputStatus::Processing || $job->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }

    /**
     * Nền tảng người dùng chọn trong popup "Đăng lên đâu?" (bắt buộc, một hoặc nhiều). Chọn nền tảng chưa có tài khoản
     * vẫn tạo job (chờ tới khi admin gắn worker). Nền tảng đã đăng xong không đăng lại (tránh bài trùng).
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

    /** @param  SocialPlatform[]  $platforms */
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
     *
     * @return array<int, array{image_id: ?int, output: GenerationOutput}> theo thứ tự (image_id null: ảnh mới thêm)
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

        $outputIds = array_map(fn ($r) => $r['output']->id, $resolved);
        if (count($outputIds) !== count(array_unique($outputIds))) {
            throw ValidationException::withMessages(['images' => 'Có ảnh bị trùng trong bài đăng.']);
        }
        if (collect($resolved)->contains(fn ($r) => $r['output']->status !== OutputStatus::Completed)) {
            throw new BusinessException('IMAGES_NOT_READY', 'Còn ảnh đang tạo hoặc bị lỗi. Hãy bỏ ảnh đó khỏi bài trước khi đăng.', 409);
        }

        return array_values($resolved);
    }

    /** @param  array<int, array{image_id: ?int, output: GenerationOutput}>  $images */
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
