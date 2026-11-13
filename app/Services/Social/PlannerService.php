<?php

namespace App\Services\Social;

use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\PlanContext;
use App\Models\Product;
use App\Models\SocialContentJob;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GenerationService;
use App\Services\MediaStorage;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kế hoạch nội dung (FlowKit a51-skills/ke-hoach-noi-dung/thiet-ke.txt mục 9). Skill planner đọc context rồi tạo
 * từng bài: sản phẩm + giờ đăng + bối cảnh + kịch bản (ý chính + 1 câu lệnh / ảnh). Bài đi luồng generating có sẵn:
 * ảnh xong -> AI viết caption (theo ý chính) -> chờ duyệt. Mọi thứ của bài kế hoạch đều miễn phí.
 */
class PlannerService
{
    public const MIN_ITEMS = 1;

    public const MAX_ITEMS = 6;

    public const ITEM_MAX = 1500;

    public const IDEA_MAX = 1000;

    public function __construct(
        private GenerationService $generations,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /** Mọi thứ planner cần để lập kế hoạch các ngày [from, to] (ngày theo ai90.social.planner_timezone). */
    public function context(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $products = Product::query()
            ->accessibleBy($user)
            ->active()
            ->whereHas('images')
            ->with('images')
            ->orderBy('id')
            ->get();
        $lastPlanned = SocialPost::query()
            ->where('user_id', $user->id)
            ->where('source', SocialPost::SOURCE_PLAN)
            ->whereIn('product_id', $products->pluck('id'))
            ->groupBy('product_id')
            ->selectRaw('product_id, max(scheduled_at) as last_at')
            ->pluck('last_at', 'product_id');

        $posts = SocialPost::query()
            ->where('user_id', $user->id)
            // Cột lưu UTC: đổi mốc ngày (giờ Việt Nam) sang UTC trước khi so
            ->whereBetween('scheduled_at', [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()])
            ->orderBy('scheduled_at')
            ->get(['id', 'type', 'source', 'status', 'product_id', 'scheduled_at']);

        return [
            'timezone' => config('ai90.social.planner_timezone'),
            'now' => now(),
            'limits' => ['min_items' => self::MIN_ITEMS, 'max_items' => self::MAX_ITEMS, 'item_max' => self::ITEM_MAX, 'idea_max' => self::IDEA_MAX],
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'thumb_url' => $p->images->first() ? $this->media->url($p->images->first()->path) : null,
                'image_fields' => $p->images->pluck('field_key')->values(),
                'last_planned_at' => $lastPlanned[$p->id] ?? null,
            ])->values(),
            'contexts' => PlanContext::query()->where('user_id', $user->id)->active()->orderBy('id')->get()
                ->map(fn (PlanContext $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'description' => $c->description,
                    'aspect_ratio' => $c->aspect_ratio,
                ])->values(),
            'posts' => $posts->map(fn (SocialPost $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'source' => $p->source,
                'status' => $p->status,
                'product_id' => $p->product_id,
                'scheduled_at' => $p->scheduled_at,
            ])->values(),
        ];
    }

    /**
     * Tạo 1 bài kế hoạch (bài ảnh). Gọi lặp cùng idempotency_key trả lại bài đã tạo.
     *
     * @param  array{idempotency_key: string, product_id: int, context_id: int, scheduled_at: CarbonInterface, script: array{y_chinh: string, items: string[]}}  $data
     */
    public function createPost(User $user, array $data): SocialPost
    {
        if ($existing = $this->findExisting($user, $data['idempotency_key'])) {
            return $existing;
        }

        $product = $this->product($user, (int) $data['product_id']);
        $context = $this->planContext($user, (int) $data['context_id']);
        $script = self::script($data['script']);

        try {
            return DB::transaction(function () use ($user, $data, $product, $context, $script) {
                $post = SocialPost::query()->create([
                    'user_id' => $user->id,
                    'type' => SocialPost::TYPE_PHOTO,
                    'status' => SocialPostStatus::Generating,
                    'source' => SocialPost::SOURCE_PLAN,
                    'script' => $script,
                    'plan_context_id' => $context->id,
                    'product_id' => $product->id,
                    'scheduled_at' => $data['scheduled_at'],
                    'platforms' => ['tiktok'],
                    'ai_content' => SocialContentJobService::enabled(),
                    'idempotency_key' => $data['idempotency_key'],
                ]);
                $this->createImages($user, $post, $context, $product);

                $this->audit->log('social_post.plan', $post, [
                    'product_id' => $product->id, 'context_id' => $context->id, 'images' => count($script['items']),
                ], $user->id);

                return $post;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->findExisting($user, $data['idempotency_key']) ?? throw $e;
        }
    }

    /**
     * "Duyệt": bài kế hoạch chờ duyệt -> chờ gửi, lưu nội dung chủ shop vừa sửa (gửi kèm thì ghi đè).
     * Bài còn ảnh lỗi thì phải làm lại trước. Sau khi duyệt không làm lại ảnh được nữa (bỏ duyệt trước).
     *
     * @param  array{title?: ?string, content?: ?string, hashtags?: ?array}  $content
     */
    public function approve(User $user, SocialPost $post, array $content): SocialPost
    {
        return DB::transaction(function () use ($user, $post, $content) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            if (! $post->isPlan() || $post->status !== SocialPostStatus::AwaitingApproval) {
                throw BusinessException::planPostNotApprovable('Chỉ duyệt được bài kế hoạch đang chờ duyệt.');
            }
            if (($post->last_error['code'] ?? null) === SocialPost::PLAN_IMAGES_FAILED) {
                throw BusinessException::planPostNotApprovable('Bài còn ảnh tạo lỗi, hãy bấm "Làm lại" trước khi duyệt.');
            }

            $post->update([
                ...(array_key_exists('title', $content) ? ['title' => trim((string) $content['title'])] : []),
                ...(array_key_exists('content', $content) ? ['content' => $content['content']] : []),
                ...(array_key_exists('hashtags', $content) ? ['hashtags' => SocialPost::normalizeHashtags($content['hashtags'] ?? []) ?: null] : []),
                'status' => SocialPostStatus::PendingReview,
            ]);
            $this->audit->log('social_post.plan_approve', $post, [], $user->id);

            return $post;
        });
    }

    /** "Bỏ duyệt": bài kế hoạch đã duyệt nhưng chưa bấm đăng lần nào -> về chờ duyệt (để làm lại ảnh). */
    public function unapprove(User $user, SocialPost $post): SocialPost
    {
        return DB::transaction(function () use ($user, $post) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            if (! $post->isPlan() || $post->status !== SocialPostStatus::PendingReview || $post->platformStatuses() !== []) {
                throw BusinessException::planPostNotApprovable('Chỉ bỏ duyệt được bài kế hoạch đã duyệt và chưa bấm đăng.');
            }

            $post->update(['status' => SocialPostStatus::AwaitingApproval]);
            $this->audit->log('social_post.plan_unapprove', $post, [], $user->id);

            return $post;
        });
    }

    /**
     * "Làm lại": bài kế hoạch chưa bấm đăng -> bỏ ảnh + nội dung cũ, tạo lại ảnh theo đúng kịch bản (bối cảnh hiện tại),
     * về generating. Miễn phí.
     */
    public function redo(User $user, SocialPost $post): SocialPost
    {
        return DB::transaction(function () use ($user, $post) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            if (! $post->isPlan() || $post->status !== SocialPostStatus::AwaitingApproval) {
                throw BusinessException::planPostNotRedoable();
            }
            $context = $post->plan_context_id ? PlanContext::query()->find($post->plan_context_id) : null;
            if (! $context?->is_active) {
                throw BusinessException::planContextUnavailable();
            }
            $product = $this->product($user, (int) $post->product_id);

            $post->images()->delete();
            SocialContentJob::query()->where('social_post_id', $post->id)->delete();
            $post->update([
                'status' => SocialPostStatus::Generating,
                'title' => '',
                'content' => null,
                'hashtags' => null,
                'last_error' => null,
                'ai_content' => SocialContentJobService::enabled(),
            ]);
            $this->createImages($user, $post, $context, $product);

            $this->audit->log('social_post.plan_redo', $post, ['images' => count($post->script['items'] ?? [])], $user->id);

            return $post;
        });
    }

    /**
     * "Tạo lại ảnh này": chỉ tạo lại 1 ảnh của bài kế hoạch theo đúng câu lệnh của ảnh đó trong kịch bản (bối cảnh hiện tại),
     * các ảnh khác giữ nguyên, miễn phí. Bài về generating; ảnh xong thì về chờ duyệt, giữ nguyên nội dung đã có.
     * Bài đang báo ảnh lỗi (chưa có nội dung AI) thì xoá nội dung mặc định để AI viết lại khi đủ ảnh.
     * Ảnh cũ không gắn vào bài nữa (vẫn trong lịch sử của generation).
     */
    public function redoImage(User $user, SocialPost $post, int $imageId): SocialPost
    {
        return DB::transaction(function () use ($user, $post, $imageId) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $image = $post->images()->lockForUpdate()->findOrFail($imageId);
            if (! $post->isPlan() || $post->status !== SocialPostStatus::AwaitingApproval) {
                throw BusinessException::planPostNotRedoable();
            }
            // Câu lệnh của ảnh: theo số thứ tự trong key "plan:{bài}:{lượt}:{n}" của generation (ảnh đổi chỗ vẫn đúng câu);
            // ảnh không tạo từ kịch bản (thêm từ A51, upload) thì không tạo lại được
            $key = $image->generation_id ? (string) $image->generation()->value('idempotency_key') : '';
            $item = preg_match('/^plan:'.$post->id.':[^:]+:(\d+)$/', $key, $m) ? (int) $m[1] : 0;
            $prompt = $item > 0 ? (array_values($post->script['items'] ?? [])[$item - 1] ?? null) : null;
            if (! $prompt) {
                throw BusinessException::planPostNotRedoable();
            }
            $context = $post->plan_context_id ? PlanContext::query()->find($post->plan_context_id) : null;
            if (! $context?->is_active) {
                throw BusinessException::planContextUnavailable();
            }
            $product = $this->product($user, (int) $post->product_id);

            $generation = $this->generations->createForPlan(
                $user, $context, $product, $prompt, "plan:{$post->id}:".Str::uuid()->toString().":{$item}",
            );
            $old = $image->output_id;
            $image->update(['generation_id' => $generation->id, 'output_id' => $generation->outputs->first()->id]);

            $imagesFailed = ($post->last_error['code'] ?? null) === SocialPost::PLAN_IMAGES_FAILED;
            $post->update([
                'status' => SocialPostStatus::Generating,
                'last_error' => null,
                // Còn ảnh lỗi thì bài chưa có nội dung AI: xoá nội dung mặc định để AI viết khi đủ ảnh
                ...($imagesFailed ? ['title' => '', 'content' => null, 'hashtags' => null] : []),
            ]);

            $this->audit->log('social_post.plan_redo_image', $post, [
                'image_id' => $image->id, 'item' => $item, 'old_output_id' => $old, 'generation_id' => $generation->id,
            ], $user->id);

            return $post;
        });
    }

    private function createImages(User $user, SocialPost $post, PlanContext $context, Product $product): void
    {
        $batch = Str::uuid()->toString();
        foreach (array_values($post->script['items'] ?? []) as $i => $prompt) {
            $generation = $this->generations->createForPlan($user, $context, $product, $prompt, "plan:{$post->id}:{$batch}:".($i + 1));
            $post->images()->create([
                'generation_id' => $generation->id,
                'output_id' => $generation->outputs->first()->id,
                'position' => $i + 1,
            ]);
        }
    }

    private function product(User $user, int $productId): Product
    {
        return Product::query()->accessibleBy($user)->active()->whereHas('images')->with('images')->find($productId)
            ?? throw BusinessException::planProductUnavailable();
    }

    private function planContext(User $user, int $contextId): PlanContext
    {
        return PlanContext::query()->where('user_id', $user->id)->active()->find($contextId)
            ?? throw BusinessException::planContextUnavailable();
    }

    /** @return array{y_chinh: string, items: string[]} */
    private static function script(array $script): array
    {
        return [
            'y_chinh' => trim((string) ($script['y_chinh'] ?? '')),
            'items' => array_values(array_map(fn ($item) => trim((string) $item), $script['items'] ?? [])),
        ];
    }

    private function findExisting(User $user, string $idempotencyKey): ?SocialPost
    {
        $existing = SocialPost::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing && ! $existing->isPlan()) {
            throw BusinessException::idempotencyConflict();
        }

        return $existing;
    }
}
