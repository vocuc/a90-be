<?php

namespace App\Services\Social;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\PublishDriver;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use App\Models\Product;
use App\Models\PublishAccount;
use App\Models\SocialPost;
use App\Models\SocialPublishJob;
use App\Models\User;
use App\Models\VideoTemplate;
use App\Models\Worker;
use App\Services\AuditLogger;
use App\Services\ShopImageLibrary;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bài đăng video có lịch (note/lap-lich-worker.txt ở repo frontend).
 * - Chủ shop bấm "Tạo video": chọn worker, số bài, giờ đăng bài đầu, khoảng cách tối thiểu / tối đa giữa 2 bài. Mỗi bài:
 *   template ngẫu nhiên (có số ảnh), sản phẩm ngẫu nhiên đủ ảnh, ảnh ngẫu nhiên của sản phẩm; AI viết tiêu đề / nội dung /
 *   hashtag (trừ phí từng bài, hết tiền thì dừng, bài đã tạo giữ nguyên). Giờ bài sau = bài trước + số phút ngẫu nhiên.
 * - Sửa mọi thứ khi worker chưa nhận (queued) hoặc bài lỗi (pending_review).
 * - Worker (đúng worker được chọn, giữ tài khoản đăng bài của shop hoặc được gắn làm worker tạo video của shop) nhận bài đã tới giờ, dựng video, tự đăng rồi báo lại.
 *   Job không hết hạn: worker im lặng thì bài giữ "Đang đăng", chủ shop tự bấm "Tạo lại" (có thể đăng trùng).
 *   Lỗi -> bài chờ gửi kèm last_error, chủ shop sửa rồi bấm "Đăng lại".
 * Mỗi lần worker nhận là 1 SocialPublishJob (lịch sử, mã lỗi cho admin), complete / fail dùng SocialPublishJobService.
 */
class VideoPostService
{
    public const MAX_COUNT = 50;

    /** Khoảng cách tối đa giữa 2 bài (phút): 1 ngày. */
    public const MAX_GAP_MINUTES = 1440;

    public const STOP_INSUFFICIENT_CREDIT = 'insufficient_credit';

    public const STOP_NO_TEMPLATE = 'no_template';

    public const STOP_NOT_ENOUGH_IMAGES = 'not_enough_images';

    public function __construct(
        private ShopImageLibrary $library,
        private SocialContentJobService $contentJobs,
        private SocialPostService $posts,
        private AuditLogger $audit,
    ) {}

    /**
     * Tạo tối đa $count bài video. Trả số bài đã tạo và lý do dừng sớm (null: tạo đủ).
     * Gửi lại cùng idempotency_key: trả kết quả lần trước, không tạo thêm.
     *
     * @return array{created: int, requested: int, stopped: ?string, post_ids: int[]}
     */
    public function autoCreate(User $user, Worker $worker, int $count, CarbonInterface $firstAt, int $gapMin, int $gapMax, string $idempotencyKey): array
    {
        $existing = SocialPost::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', 'like', self::keyPrefix($idempotencyKey).'%')
            ->orderBy('id')
            ->pluck('id');
        if ($existing->isNotEmpty()) {
            return ['created' => $existing->count(), 'requested' => $count, 'stopped' => null, 'post_ids' => $existing->all()];
        }

        $templates = VideoTemplate::query()->where('user_id', $user->id)->whereNotNull('image_count')->get();
        $counts = $this->library->countsPerProduct($user->id);

        $ids = [];
        $stopped = null;
        $at = $firstAt->copy();
        for ($i = 0; $i < $count; $i++) {
            if ($templates->isEmpty()) {
                $stopped = self::STOP_NO_TEMPLATE;
                break;
            }
            $pick = $this->pickTemplateAndProduct($templates, $counts);
            if (! $pick) {
                $stopped = self::STOP_NOT_ENOUGH_IMAGES;
                break;
            }
            [$template, $productId] = $pick;
            $outputs = $this->library->productImages($user->id, $productId)->inRandomOrder()->limit($template->image_count)->get();

            if ($i > 0) {
                $at = $at->copy()->addMinutes(random_int($gapMin, $gapMax));
            }

            try {
                $ids[] = $this->createPost($user, $worker, $template, $productId, $outputs, $at, self::keyPrefix($idempotencyKey).$i)->id;
            } catch (BusinessException $e) {
                if ($e->errorCode !== 'INSUFFICIENT_CREDIT') {
                    throw $e;
                }
                $stopped = self::STOP_INSUFFICIENT_CREDIT;
                break;
            }
        }

        $this->audit->log('video_post.auto_create', null, [
            'worker_id' => $worker->id, 'requested' => $count, 'created' => count($ids), 'stopped' => $stopped,
        ], $user->id);

        return ['created' => count($ids), 'requested' => $count, 'stopped' => $stopped, 'post_ids' => $ids];
    }

    /**
     * Sửa bài video khi worker chưa nhận (queued) hoặc bài lỗi chờ đăng lại (pending_review). Số ảnh phải bằng số ảnh
     * template cần. Không đổi trạng thái: bài lỗi vẫn chờ chủ shop bấm "Đăng lại".
     *
     * @param  array{worker_id: int, video_template_id: int, product_id: ?int, images: array<int, array{generation_id: int, output_id: int}>, title?: ?string, content?: ?string, hashtags?: ?array, scheduled_at: string}  $data
     */
    public function update(User $user, SocialPost $post, array $data): SocialPost
    {
        $this->assertVideo($post);
        $worker = $this->findWorker($user, (int) $data['worker_id']);
        $template = VideoTemplate::query()->where('user_id', $user->id)->find((int) $data['video_template_id'])
            ?? throw ValidationException::withMessages(['video_template_id' => 'Template không tồn tại.']);
        if (! $template->image_count) {
            throw ValidationException::withMessages(['video_template_id' => 'Template chưa có số ảnh, hãy sửa template trước.']);
        }
        $productId = isset($data['product_id']) ? (int) $data['product_id'] : null;
        if ($productId && ! Product::query()->where('user_id', $user->id)->whereKey($productId)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'Sản phẩm không tồn tại.']);
        }
        if (count($data['images']) !== $template->image_count) {
            throw ValidationException::withMessages(['images' => "Template \"{$template->name}\" cần đúng {$template->image_count} ảnh."]);
        }
        $outputs = $this->posts->resolveOwnedOutputs($user, array_values($data['images']), 'images');

        return DB::transaction(function () use ($user, $post, $data, $worker, $template, $productId, $outputs) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $this->assertEditable($post);

            $post->update([
                'worker_id' => $worker->id,
                ...self::templateSnapshot($template),
                'product_id' => $productId,
                'scheduled_at' => $data['scheduled_at'],
                'title' => trim((string) ($data['title'] ?? '')),
                'content' => $data['content'] ?? '',
                'hashtags' => SocialPost::normalizeHashtags($data['hashtags'] ?? []),
            ]);
            $post->images()->delete();
            foreach ($outputs->values() as $i => $output) {
                $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
            }

            $this->audit->log('video_post.update', $post, ['images' => $outputs->count()], $user->id);

            return $post;
        });
    }

    /**
     * "Đăng lại" (bài lỗi) / "Tạo lại" (worker nhận rồi im lặng): bài về chờ tới giờ cho worker nhận lại.
     * Job đang chạy bị bỏ: worker cũ báo kết quả sau đó -> 409 JOB_NOT_ASSIGNED. Chủ shop tự chịu rủi ro đăng trùng.
     */
    public function retry(User $user, SocialPost $post): SocialPost
    {
        $this->assertVideo($post);

        return DB::transaction(function () use ($user, $post) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            if (! in_array($post->status, [SocialPostStatus::PendingReview, SocialPostStatus::Publishing], true)) {
                throw new BusinessException('POST_NOT_RETRYABLE', match ($post->status) {
                    SocialPostStatus::Published => 'Bài đã đăng xong.',
                    SocialPostStatus::Generating => 'Bài đang được tạo nội dung.',
                    default => 'Bài đang chờ tới giờ đăng.',
                }, 409);
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
            foreach (SocialPlatform::cases() as $platform) {
                $post->{$platform->statusColumn()} = null;
            }
            $post->status = SocialPostStatus::Queued;
            $post->last_error = null;
            $post->save();

            $this->audit->log('video_post.retry', $post, [], $user->id);

            return $post;
        });
    }

    /**
     * Worker nhận bài video đã tới giờ của nó (cũ nhất trước), bài của tài khoản A51 worker được gắn:
     * - worker giữ tài khoản đăng bài (publish_accounts): bài của chủ tài khoản đó, nền tảng = nền tảng tài khoản;
     * - không thì worker tạo video (admin -> Worker -> Tài khoản tạo video, workers.video_user_id): đăng TikTok bằng
     *   tài khoản đang đăng nhập trên điện thoại của worker (job không gắn tài khoản đăng bài, request.account = null).
     * Không gắn gì -> 409 ACCOUNT_NOT_ASSIGNED. SKIP LOCKED để gọi song song không nhận trùng.
     */
    public function claim(Worker $worker): ?SocialPublishJob
    {
        $account = PublishAccount::query()->where('worker_id', $worker->id)->first();
        if ($account) {
            if (! $account->is_active || ! $account->user_id) {
                throw new BusinessException($account->platform->notAssignedCode(), 'Worker chưa được gắn tài khoản A51 hoặc đang bị tắt.', 409);
            }
            [$userId, $platform] = [$account->user_id, $account->platform];
        } elseif ($worker->video_user_id) {
            [$userId, $platform] = [$worker->video_user_id, SocialPlatform::Tiktok];
        } else {
            throw new BusinessException('ACCOUNT_NOT_ASSIGNED', 'Worker chưa được gắn tài khoản đăng bài hoặc tài khoản tạo video.', 409);
        }

        return DB::transaction(function () use ($worker, $account, $userId, $platform) {
            $post = SocialPost::query()
                ->where('type', SocialPost::TYPE_VIDEO)
                ->where('status', SocialPostStatus::Queued)
                ->where('worker_id', $worker->id)
                ->where('user_id', $userId)
                ->where('scheduled_at', '<=', now())
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();
            if (! $post) {
                return null;
            }

            // Không đặt hạn: worker im lặng thì bài giữ "Đang đăng" tới khi chủ shop bấm tạo lại
            $job = $post->publishJobs()->create([
                'platform' => $platform,
                'status' => OutputStatus::Processing,
                'driver' => PublishDriver::Worker,
                'worker_id' => $worker->id,
                'publish_account_id' => $account?->id,
            ]);
            $post->setPlatformStatus($platform, PlatformPublishStatus::Publishing);

            return $job;
        });
    }

    /** Thông tin template + sản phẩm gửi worker kèm dữ liệu bài (SocialPublishJobService::request). */
    public function videoRequest(SocialPublishJob $job): array
    {
        $post = $job->post()->with('product')->firstOrFail();

        return [
            'template' => [
                'id' => $post->video_template_id,
                'name' => $post->video_template_name,
                'video_url' => $post->video_template_url,
                'image_count' => $post->video_image_count,
            ],
            'product' => $post->product ? ['id' => $post->product->id, 'name' => $post->product->name] : null,
            'scheduled_at' => $post->scheduled_at,
        ];
    }

    /** Worker đang bật được gắn cho shop; không thì 422 (chọn worker trong popup). */
    public function findWorker(User $user, int $workerId): Worker
    {
        return Worker::query()->assignedTo($user->id)->find($workerId)
            ?? throw ValidationException::withMessages(['worker_id' => 'Worker không được gắn cho shop hoặc đang tắt.']);
    }

    /**
     * Template ngẫu nhiên (trong các template có sản phẩm đủ ảnh) và sản phẩm ngẫu nhiên đủ ảnh cho template đó.
     *
     * @param  Collection<int, VideoTemplate>  $templates
     * @param  Collection<int, int>  $counts  product_id => số ảnh
     * @return array{0: VideoTemplate, 1: int}|null
     */
    private function pickTemplateAndProduct(Collection $templates, Collection $counts): ?array
    {
        foreach ($templates->shuffle() as $template) {
            $products = $counts->filter(fn (int $total) => $total >= $template->image_count)->keys();
            if ($products->isNotEmpty()) {
                return [$template, (int) $products->random()];
            }
        }

        return null;
    }

    /** @param  Collection<int, GenerationOutput>  $outputs */
    private function createPost(User $user, Worker $worker, VideoTemplate $template, int $productId, Collection $outputs, CarbonInterface $at, string $key): SocialPost
    {
        $ai = SocialContentJobService::enabled();

        return DB::transaction(function () use ($user, $worker, $template, $productId, $outputs, $at, $key, $ai) {
            $post = SocialPost::query()->create([
                'user_id' => $user->id,
                'type' => SocialPost::TYPE_VIDEO,
                'status' => SocialPostStatus::Generating,
                'source' => SocialPost::SOURCE_VIDEO,
                'ai_content' => $ai,
                'worker_id' => $worker->id,
                ...self::templateSnapshot($template),
                'product_id' => $productId,
                'scheduled_at' => $at,
                'idempotency_key' => $key,
            ]);
            foreach ($outputs->values() as $i => $output) {
                $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
            }

            // AI viết: trừ phí ngay (không đủ -> 402, rollback bài này); tắt AI: nội dung mặc định từ mẫu, chờ tới giờ
            $ai
                ? $this->contentJobs->queue($post)
                : $post->update([...$post->defaultContent(), 'status' => SocialPostStatus::Queued]);

            return $post;
        });
    }

    private static function templateSnapshot(VideoTemplate $template): array
    {
        return [
            'video_template_id' => $template->id,
            'video_template_name' => $template->name,
            'video_template_url' => $template->video_url,
            'video_image_count' => $template->image_count,
        ];
    }

    private static function keyPrefix(string $idempotencyKey): string
    {
        return 'video:'.$idempotencyKey.':';
    }

    private function assertVideo(SocialPost $post): void
    {
        if (! $post->isVideo()) {
            throw new BusinessException('NOT_VIDEO_POST', 'Đây không phải bài video.', 409);
        }
    }

    private function assertEditable(SocialPost $post): void
    {
        if (! in_array($post->status, [SocialPostStatus::Queued, SocialPostStatus::PendingReview], true)) {
            throw new BusinessException('POST_NOT_EDITABLE', match ($post->status) {
                SocialPostStatus::Generating => 'AI đang viết nội dung, vui lòng chờ xong rồi sửa.',
                SocialPostStatus::Publishing => 'Worker đã nhận bài, không sửa được nữa.',
                default => 'Bài đã đăng xong, không sửa được.',
            }, 409);
        }
    }
}
