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
     * "Làm lại": bài kế hoạch chưa bấm đăng -> bỏ ảnh + nội dung cũ, tạo lại ảnh theo đúng kịch bản (bối cảnh hiện tại),
     * về generating. Miễn phí.
     */
    public function redo(User $user, SocialPost $post): SocialPost
    {
        return DB::transaction(function () use ($user, $post) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            if (! $post->isPlan() || $post->status !== SocialPostStatus::PendingReview || $post->platformStatuses() !== []) {
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
