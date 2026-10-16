<?php

namespace App\Services\Social;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\SocialPlatform;
use App\Exceptions\BusinessException;
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
 * Đăng bài lên TikTok / Facebook / Instagram qua worker (note/dang-bai-da-nen-tang.txt ở repo frontend), không dùng API
 * của các nền tảng: bấm đăng -> lưu chỉnh sửa, tạo 1 job / nền tảng đã chọn, nền tảng sang queued.
 * Admin gắn tài khoản A51 với worker theo từng nền tảng (PublishAccount); worker chỉ nhận job của nền tảng và tài khoản
 * A51 được gắn (publishing), đăng xong báo completed (published). Lỗi / quá hạn không giao lại: nền tảng sang failed,
 * xong hết thì bài về pending_review với thông báo chung, người dùng bấm đăng lại các nền tảng lỗi. Job chờ không hết hạn.
 */
class SocialPublishJobService
{
    public function __construct(
        private SocialPostService $posts,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /**
     * Tài khoản đăng bài admin đã gắn cho người dùng, theo thứ tự nền tảng.
     *
     * @return Collection<int, PublishAccount>
     */
    public function accounts(User $user): Collection
    {
        $order = array_flip(SocialPlatform::values());

        return $user->publishAccounts()->get()->sortBy(fn (PublishAccount $account) => $order[$account->platform->value])->values()->toBase();
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

        return DB::transaction(function () use ($user, $post, $data, $hashtags, $images, $platforms) {
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
                $jobs[$platform->value] = $post->publishJobs()->create(['platform' => $platform, 'status' => OutputStatus::Queued])->id;
            }
            $post->syncStatus();
            $post->save();

            $this->audit->log('social_post.publish', $post, ['jobs' => $jobs, 'images' => count($images)], $user->id);

            return $post;
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
            $job = $this->lockAssigned($worker, $job);
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
                $this->failJob($job, 'WORKER_TIMEOUT', 'Worker không trả kết quả trong thời gian cho phép.');
                $failed++;
            });
        }

        return $failed;
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
