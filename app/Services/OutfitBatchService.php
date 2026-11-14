<?php

namespace App\Services;

use App\Enums\CreativeStatus;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\OutputStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\AiCreative;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Generation;
use App\Models\ImageBatch;
use App\Models\ImageBatchImage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lô phối đồ "1 đôi giày – nhiều cách phối" (D:\FlowKit\a51-skills\outfit-video\thiet-ke.txt).
 * Mỗi lô = 1 sản phẩm x N ảnh: ảnh 1 kind outfit_cover (ảnh sản phẩm + hook), ảnh 2..N kind outfit (ChatGPT).
 * A51 không chọn outfit: worker phối đồ (skill Claude) tự chọn / tạo outfit trên OOTD.
 * Ảnh gắn với 1 creative hệ thống ẩn (để dùng lại luồng lô, lịch sử, đăng bài), không trừ tiền.
 */
class OutfitBatchService
{
    public const MAX_COUNT = 50;

    public const MIN_IMAGES = 2;

    public const MAX_IMAGES = 10;

    public const DEFAULT_IMAGES = 5;

    public const SYSTEM_SLUG = 'he-thong-phoi-do';

    public const ASPECT = '3:4';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Sản phẩm sẽ dùng: đang hoạt động, người dùng truy cập được, ít lô phối đồ nhất trước, bằng nhau thì tạo trước.
     *
     * @param  int[]|null  $productIds
     * @return Collection<int, Product>
     */
    public function plan(User $user, int $count, ?array $productIds = null): Collection
    {
        $products = Product::query()->accessibleBy($user)->active()
            ->when($productIds !== null, fn ($q) => $q->whereKey($productIds))
            ->with(['images', 'coverImage'])->orderBy('id')->get();
        $counts = DB::table('generations')
            ->where('user_id', $user->id)->where('kind', Generation::KIND_OUTFIT_COVER)
            ->whereIn('product_id', $products->modelKeys())
            ->groupBy('product_id')->selectRaw('product_id, COUNT(*) AS total')->pluck('total', 'product_id');

        return $products->filter(fn (Product $p) => $p->images->isNotEmpty())
            ->sortBy(fn (Product $p) => [(int) ($counts[$p->id] ?? 0), $p->id])
            ->values()->take($count);
    }

    /**
     * Tạo tối đa $count lô; gửi lại cùng idempotency key trả đúng các lô đã tạo.
     *
     * @param  int[]|null  $productIds
     * @return ImageBatch[]
     */
    public function create(User $user, int $count, int $images, bool $insertLogo, string $idempotencyKey, int $workerId, ?array $productIds = null): array
    {
        $lock = Cache::lock("outfit-batch:{$user->id}", 120);
        $lock->block(30);
        try {
            $existing = ImageBatch::query()->where('user_id', $user->id)
                ->where('idempotency_key', 'like', "outfit:{$idempotencyKey}:%")->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }
            $products = $this->plan($user, $count, $productIds);
            if ($products->isEmpty()) {
                throw new BusinessException('OUTFIT_NO_PRODUCT', 'Chưa có sản phẩm đang hoạt động (có ảnh) để tạo lô phối đồ.', 422);
            }
            $creative = $this->systemCreative($user);
            $created = [];
            foreach ($products->values() as $n => $product) {
                $created[] = DB::transaction(function () use ($user, $product, $images, $insertLogo, $workerId, $creative, $idempotencyKey, $n) {
                    $batch = ImageBatch::query()->create([
                        'user_id' => $user->id,
                        'idempotency_key' => "outfit:{$idempotencyKey}:{$n}",
                        'source' => ImageBatch::SOURCE_OUTFIT,
                    ]);
                    $paths = $product->images->pluck('path', 'field_key')->all();
                    for ($pos = 1; $pos <= $images; $pos++) {
                        $generation = $this->newGeneration($user, $creative, $pos === 1 ? Generation::KIND_OUTFIT_COVER : Generation::KIND_OUTFIT,
                            $paths, $product->id, $workerId, $insertLogo, "outfit:{$batch->id}:{$pos}");
                        $batch->images()->create([
                            'generation_id' => $generation->id,
                            'output_id' => $generation->outputs->first()->id,
                            'position' => $pos,
                        ]);
                    }
                    $this->audit->log('image_batch.create_outfit', $batch, ['product_id' => $product->id, 'images' => $images], $user->id);

                    return $batch;
                });
            }

            return $created;
        } finally {
            $lock->release();
        }
    }

    /** Đưa 1 ảnh phối đồ về hàng chờ: generation mới cùng kind / nguyên liệu / worker (như "Tạo lại"). */
    public function requeue(User $user, ImageBatch $batch, ImageBatchImage $image, string $idempotencyKey): ImageBatchImage
    {
        return DB::transaction(function () use ($user, $batch, $image, $idempotencyKey) {
            $image = $batch->images()->lockForUpdate()->findOrFail($image->id);
            $original = $image->generation()->firstOrFail();
            $output = $image->output()->firstOrFail();
            if (! $output->status->isFinal()) {
                throw new BusinessException('IMAGE_NOT_READY', 'Ảnh đang được tạo, vui lòng chờ xong rồi tạo lại.', 409);
            }
            $generation = Generation::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first()
                ?? $this->newGeneration($user, $original->creative()->firstOrFail(), $original->kind, $original->input_paths ?? [],
                    $original->product_id, $original->worker_id, (bool) $original->insert_logo, $idempotencyKey, $original);
            $image->update(['generation_id' => $generation->id, 'output_id' => $generation->outputs()->first()->id]);
            $this->audit->log('image_batch.regenerate', $batch, ['image_id' => $image->id, 'generation_id' => $generation->id], $user->id);

            return $image;
        });
    }

    /** "Tạo lại ảnh lỗi": mọi ảnh lỗi của lô phối đồ về hàng chờ. Trả về số ảnh. */
    public function retryFailed(User $user, ImageBatch $batch, string $idempotencyKey): int
    {
        $failed = $batch->images()->with('output')->get()
            ->filter(fn (ImageBatchImage $i) => $i->output?->status === OutputStatus::Failed);
        foreach ($failed as $image) {
            $this->requeue($user, $batch, $image, "{$idempotencyKey}:{$image->id}");
        }

        return $failed->count();
    }

    private function newGeneration(User $user, AiCreative $creative, string $kind, array $paths, ?int $productId, ?int $workerId,
        bool $insertLogo, string $idempotencyKey, ?Generation $parent = null): Generation
    {
        $generation = Generation::query()->create([
            'user_id' => $user->id,
            'creative_id' => $creative->id,
            'creator_id' => $creative->creator_id,
            'model_id' => $creative->model_id,
            'parent_generation_id' => $parent?->id,
            'type' => GenerationType::Owner,
            'kind' => $kind,
            'input_paths' => $paths,
            'input_values' => [],
            'aspect_ratio' => self::ASPECT,
            'quantity' => 1,
            'price_per_image' => 0,
            'prompt_snapshot' => '',
            'status' => GenerationStatus::Queued,
            'idempotency_key' => $idempotencyKey,
            'insert_logo' => $insertLogo,
            'worker_id' => $workerId,
            'product_id' => $productId ?? 0,
        ]);
        $generation->outputs()->create(['position' => 1, 'status' => OutputStatus::Queued]);

        return $generation->load('outputs');
    }

    /**
     * Creative hệ thống ẩn mà mọi ảnh phối đồ gắn vào (generations cần creative / model). Bản nháp + ẩn nên không hiện ở
     * chợ creative; chủ là admin đầu tiên (không có thì chính người dùng), danh sách creative của Creator bỏ qua slug này.
     */
    public function systemCreative(User $fallbackOwner): AiCreative
    {
        if ($creative = AiCreative::query()->where('slug', self::SYSTEM_SLUG)->first()) {
            return $creative;
        }

        return DB::transaction(function () use ($fallbackOwner) {
            $provider = AiProvider::query()->firstOrCreate(['code' => 'outfit-worker'], ['name' => 'Worker phối đồ', 'is_active' => true]);
            $model = AiModel::query()->firstOrCreate(
                ['provider_id' => $provider->id, 'code' => 'outfit-chatgpt'],
                ['name' => 'Phối đồ (ChatGPT)', 'supported_aspect_ratios' => [self::ASPECT], 'is_active' => true],
            );
            $owner = User::query()->where('role', UserRole::Admin)->orderBy('id')->first() ?? $fallbackOwner;
            $creative = AiCreative::query()->create([
                'creator_id' => $owner->id,
                'model_id' => $model->id,
                'title' => 'Phối đồ',
                'slug' => self::SYSTEM_SLUG,
                'price_per_image' => 0,
                'status' => CreativeStatus::Draft,
                'hidden_at' => now(),
                'is_public' => false,
            ]);
            $creative->config()->create([
                'input_schema' => [],
                'prompt_template' => '',
                'max_outputs' => 1,
                'default_aspect_ratio' => self::ASPECT,
            ]);

            return $creative;
        });
    }
}
