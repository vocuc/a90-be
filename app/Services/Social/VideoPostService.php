<?php

namespace App\Services\Social;

use App\Enums\PlatformPublishStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use App\Models\Product;
use App\Models\SocialPost;
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
 * - Chủ shop bấm "Tạo video": chọn worker, nền tảng, số bài, giờ đăng bài đầu, khoảng cách tối thiểu / tối đa giữa 2 bài. Mỗi bài:
 *   template ngẫu nhiên (có số ảnh), sản phẩm ngẫu nhiên đủ ảnh, ảnh ngẫu nhiên của sản phẩm; AI viết tiêu đề / nội dung /
 *   hashtag (trừ phí từng bài, hết tiền thì dừng, bài đã tạo giữ nguyên). Giờ bài sau = bài trước + số phút ngẫu nhiên.
 * - Sửa mọi thứ khi worker chưa nhận (queued) hoặc bài lỗi (pending_review).
 * - Worker nhận / báo kết quả, "Đăng lại" / "Tạo lại": chung với bài ảnh (SocialPublishJobService,
 *   note/gop-luong-dang-bai.txt ở repo frontend).
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
     * @param  string[]  $platforms
     * @return array{created: int, requested: int, stopped: ?string, post_ids: int[]}
     */
    public function autoCreate(User $user, Worker $worker, array $platforms, int $count, CarbonInterface $firstAt, int $gapMin, int $gapMax, string $idempotencyKey): array
    {
        $existing = SocialPost::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', 'like', self::keyPrefix($idempotencyKey).'%')
            ->orderBy('id')
            ->pluck('id');
        if ($existing->isNotEmpty()) {
            return ['created' => $existing->count(), 'requested' => $count, 'stopped' => null, 'post_ids' => $existing->all()];
        }

        $templates = VideoTemplate::query()->where('user_id', $user->id)->usable()->get();
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
                $ids[] = $this->createPost($user, $worker, $platforms, $template, $productId, $outputs, $at, self::keyPrefix($idempotencyKey).$i)->id;
            } catch (BusinessException $e) {
                if ($e->errorCode !== 'INSUFFICIENT_CREDIT') {
                    throw $e;
                }
                $stopped = self::STOP_INSUFFICIENT_CREDIT;
                break;
            }
        }

        $this->audit->log('video_post.auto_create', null, [
            'worker_id' => $worker->id, 'platforms' => $platforms, 'requested' => $count, 'created' => count($ids), 'stopped' => $stopped,
        ], $user->id);

        return ['created' => count($ids), 'requested' => $count, 'stopped' => $stopped, 'post_ids' => $ids];
    }

    /**
     * Tab "Thủ công" của popup "Tạo video": chủ shop tự chọn worker, template, sản phẩm, ảnh (đúng số ảnh template),
     * giờ đăng -> 1 bài. AI viết tiêu đề / nội dung / hashtag như tab tự động (trừ phí; không đủ tiền -> 402, không tạo).
     * Gửi lại cùng idempotency_key: trả bài đã tạo.
     *
     * @param  array{worker_id: int, platforms: string[], video_template_id: int, product_id: ?int, images: array<int, array{generation_id: int, output_id: int}>, scheduled_at: CarbonInterface, idempotency_key: string}  $data
     */
    public function manualCreate(User $user, array $data): SocialPost
    {
        $key = 'video-manual:'.$data['idempotency_key'];
        $existing = SocialPost::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        [$worker, $template, $productId, $outputs] = $this->resolveSelection($user, $data);
        $post = $this->createPost($user, $worker, $data['platforms'], $template, $productId, $outputs, $data['scheduled_at'], $key);

        $this->audit->log('video_post.manual_create', $post, ['worker_id' => $worker->id, 'images' => $outputs->count()], $user->id);

        return $post;
    }

    /**
     * Kiểm tra lựa chọn của chủ shop (tạo thủ công / sửa): worker gắn cho shop, template của shop đang hiện + đang bật
     * (template đã tắt chỉ giữ được nếu bài đang dùng sẵn: $currentTemplateId), sản phẩm của shop, ảnh của shop đúng số
     * ảnh template cần.
     *
     * @return array{0: Worker, 1: VideoTemplate, 2: ?int, 3: Collection<int, GenerationOutput>}
     */
    private function resolveSelection(User $user, array $data, ?int $currentTemplateId = null): array
    {
        $worker = $this->findWorker($user, (int) $data['worker_id']);
        $template = $this->resolveTemplate($user, (int) $data['video_template_id'], $currentTemplateId);
        $productId = $this->resolveProductId($user, $data['product_id'] ?? null);
        if (count($data['images']) !== $template->image_count) {
            throw ValidationException::withMessages(['images' => "Template \"{$template->name}\" cần đúng {$template->image_count} ảnh."]);
        }
        $outputs = $this->posts->resolveOwnedOutputs($user, array_values($data['images']), 'images');

        return [$worker, $template, $productId, $outputs];
    }

    /**
     * Template của shop đang hiện + có số ảnh + đang bật (template đã tắt chỉ giữ được nếu bài đang dùng sẵn:
     * $currentTemplateId). Không hợp lệ -> 422 video_template_id.
     */
    public function resolveTemplate(User $user, int $templateId, ?int $currentTemplateId = null): VideoTemplate
    {
        if (! $templateId) {
            throw ValidationException::withMessages(['video_template_id' => 'Chọn video template.']);
        }
        $template = VideoTemplate::query()->where('user_id', $user->id)->visible()->find($templateId)
            ?? throw ValidationException::withMessages(['video_template_id' => 'Template không tồn tại.']);
        if (! $template->image_count) {
            throw ValidationException::withMessages(['video_template_id' => 'Template chưa có số ảnh, hãy sửa template trước.']);
        }
        if (! $template->is_enabled && $template->id !== $currentTemplateId) {
            throw ValidationException::withMessages(['video_template_id' => 'Template đã tắt, bật lại ở Quản lý video template.']);
        }

        return $template;
    }

    /** Sản phẩm của shop (để lấy ảnh theo sản phẩm), null: không theo sản phẩm. */
    public function resolveProductId(User $user, mixed $productId): ?int
    {
        $productId = $productId ? (int) $productId : null;
        if ($productId && ! Product::query()->accessibleBy($user)->whereKey($productId)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'Sản phẩm không tồn tại.']);
        }

        return $productId;
    }

    /**
     * Sửa bài video khi worker chưa nhận (queued) hoặc bài lỗi chờ đăng lại (pending_review). Số ảnh phải bằng số ảnh
     * template cần. Bài đang chờ tới giờ: nền tảng mới chọn vào hàng chờ. Bài lỗi: không đổi trạng thái, vẫn chờ chủ shop
     * bấm "Đăng lại" (đăng lên các nền tảng đã chọn).
     *
     * @param  array{worker_id: int, platforms: string[], video_template_id: int, product_id: ?int, images: array<int, array{generation_id: int, output_id: int}>, title?: ?string, content?: ?string, hashtags?: ?array, scheduled_at: string}  $data
     */
    public function update(User $user, SocialPost $post, array $data): SocialPost
    {
        $this->assertVideo($post);
        [$worker, $template, $productId, $outputs] = $this->resolveSelection($user, $data, $post->video_template_id);

        return DB::transaction(function () use ($user, $post, $data, $worker, $template, $productId, $outputs) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $this->assertEditable($post);

            $post->fill([
                'worker_id' => $worker->id,
                ...self::templateSnapshot($template),
                'product_id' => $productId,
                'scheduled_at' => $data['scheduled_at'],
                'title' => trim((string) ($data['title'] ?? '')),
                'content' => $data['content'] ?? '',
                'hashtags' => SocialPost::normalizeHashtags($data['hashtags'] ?? []),
                'platforms' => self::platformValues($post, $data['platforms']),
            ]);
            if ($post->status === SocialPostStatus::Queued) {
                $post->queuePlatforms();
            }
            $post->save();
            $post->images()->delete();
            foreach ($outputs->values() as $i => $output) {
                $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
            }

            $this->audit->log('video_post.update', $post, ['images' => $outputs->count()], $user->id);

            return $post;
        });
    }

    /** Worker đăng bài đang bật được gắn cho shop; không thì 422 (chọn worker ở trang bài / popup). */
    public function findWorker(User $user, int $workerId): Worker
    {
        return Worker::query()->assignedTo($user->id, Worker::TYPE_PUBLISH)->find($workerId)
            ?? throw ValidationException::withMessages(['worker_id' => 'Worker đăng bài không được gắn cho shop hoặc đang tắt.']);
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

    /**
     * @param  string[]  $platforms
     * @param  Collection<int, GenerationOutput>  $outputs
     */
    private function createPost(User $user, Worker $worker, array $platforms, VideoTemplate $template, ?int $productId, Collection $outputs, CarbonInterface $at, string $key): SocialPost
    {
        $ai = SocialContentJobService::enabled();

        return DB::transaction(function () use ($user, $worker, $platforms, $template, $productId, $outputs, $at, $key, $ai) {
            $post = new SocialPost([
                'user_id' => $user->id,
                'type' => SocialPost::TYPE_VIDEO,
                'status' => SocialPostStatus::Generating,
                'source' => SocialPost::SOURCE_VIDEO,
                'ai_content' => $ai,
                'worker_id' => $worker->id,
                ...self::templateSnapshot($template),
                'product_id' => $productId,
                'scheduled_at' => $at,
                'platforms' => array_values(array_unique($platforms)),
                'idempotency_key' => $key,
            ]);
            // Nền tảng chờ sẵn; AI viết xong bài sang queued (SocialPost::readyStatus) cho worker nhận khi tới giờ
            $post->queuePlatforms();
            $post->save();
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

    /** Bản chụp template lúc chọn (template bị sửa / xoá sau đó bài vẫn giữ). null: bài ảnh, xoá hết. */
    public static function templateSnapshot(?VideoTemplate $template): array
    {
        return [
            'video_template_id' => $template?->id,
            'video_template_name' => $template?->name,
            'video_template_url' => $template?->video_url,
            'video_image_count' => $template?->image_count,
        ];
    }

    /**
     * Nền tảng đã chọn khi sửa bài; nền tảng đã đăng xong không chọn lại được.
     *
     * @param  string[]  $requested
     * @return string[]
     */
    private static function platformValues(SocialPost $post, array $requested): array
    {
        $values = array_values(array_unique($requested));
        $done = array_filter($values, fn (string $v) => $post->platformStatus(SocialPlatform::from($v)) === PlatformPublishStatus::Published);
        if ($done) {
            throw ValidationException::withMessages([
                'platforms' => 'Bài đã đăng lên '.implode(', ', array_map(fn ($v) => SocialPlatform::from($v)->label(), $done)).', không đăng lại.',
            ]);
        }

        return $values;
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
