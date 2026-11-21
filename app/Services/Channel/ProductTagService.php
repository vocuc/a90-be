<?php

namespace App\Services\Channel;

use App\Models\Channel;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Product;
use App\Models\User;
use App\Services\MediaStorage;
use App\Support\ProductTags;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tag sản phẩm AI + danh mục sản phẩm cho lệnh /lap-ke-hoach (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt
 * mục 2, 5, 6). Skill /gan-tag-san-pham xem ảnh bìa 1 lần, gửi tag; lệnh lập kế hoạch chỉ đọc tag, không xem ảnh.
 */
class ProductTagService
{
    /** Ảnh tạo gần nhất của sản phẩm để chọn ngẫu nhiên 1 ảnh trả về. */
    public const RECENT_IMAGES = 10;

    public const LIST_MAX = 200;

    /** Skill chỉ xem ảnh sản phẩm có trường này (người dùng chốt 2026-11-21); sản phẩm không có -> không gắn tag được. */
    public const TAG_FIELD_KEY = 'anh_san_pham';

    public function __construct(private MediaStorage $media) {}

    /**
     * Sản phẩm đang hoạt động ĐÃ GẮN TAG mà shop dùng được (của shop + được chia sẻ), khớp bộ lọc, có ảnh tạo (không
     * phải ảnh phối đồ) của shop. Mỗi sản phẩm 1 ref ngẫu nhiên trong RECENT_IMAGES ảnh tạo gần nhất. Ít dùng trong kênh trước.
     *
     * @param  array<string, string[]>  $filter  đã ProductTags::cleanFilter
     */
    public function catalog(Channel $channel, array $filter, int $limit): array
    {
        $shop = $channel->user()->firstOrFail();
        $products = Product::query()->accessibleBy($shop)->active()->whereNotNull('tags')->orderByDesc('id')->get()
            ->filter(fn (Product $p) => ProductTags::matches($p->tags, $filter))->keyBy('id');

        $outputs = $this->recentOutputs($shop->id, $products->keys()->all());
        $usage = $this->usage($channel, $products->keys()->all());

        $rows = $products->filter(fn (Product $p) => $outputs->has($p->id))->map(function (Product $p) use ($outputs, $usage) {
            $o = $outputs->get($p->id)->random();
            $use = $usage->get($p->id);

            return [
                'id' => $p->id,
                'label' => $p->ai_label ?: $p->name,
                'tags' => $p->tags,
                'used' => (int) ($use->total ?? 0),
                'last_used_at' => $use->last_at ?? null,
                'ref' => ChannelPlanService::ref($o->generation_id, $o->id),
            ];
        })->sortBy([['used', 'asc'], ['last_used_at', 'asc']])->values();

        return [
            'products' => $rows->take($limit)->all(),
            'total' => $rows->count(),
            'untagged' => $this->untaggedCount($shop),
        ];
    }

    /** Số sản phẩm đã / chưa gắn tag của shop (context nhắc chạy /gan-tag-san-pham). */
    public function counts(User $shop): array
    {
        $tagged = Product::query()->accessibleBy($shop)->active()->whereNotNull('tags')->count();

        return ['tagged' => $tagged, 'untagged' => $this->untaggedCount($shop)];
    }

    private function untaggedCount(User $shop): int
    {
        return Product::query()->ownedBy($shop)->active()->whereNull('tags')->whereHas('images', fn ($q) => $q->where('field_key', self::TAG_FIELD_KEY))->count();
    }

    /** @return Collection<int, Collection<int, GenerationOutput>> product_id -> tối đa RECENT_IMAGES ảnh mới nhất */
    private function recentOutputs(int $shopId, array $productIds): Collection
    {
        if (! $productIds) {
            return collect();
        }

        // generation_id -> product_id (ảnh tạo cho sản phẩm, không phải ảnh phối đồ)
        $generations = Generation::query()->where('user_id', $shopId)->whereIn('product_id', $productIds)
            ->where(fn ($q) => $q->whereNull('kind')->orWhereNotIn('kind', Generation::OUTFIT_KINDS))
            ->pluck('product_id', 'id');
        if ($generations->isEmpty()) {
            return collect();
        }

        return GenerationOutput::query()->usable()->whereIn('generation_id', $generations->keys()->all())
            ->orderByDesc('id')->get(['id', 'generation_id'])
            ->groupBy(fn (GenerationOutput $o) => $generations[$o->generation_id])
            ->map(fn (Collection $rows) => $rows->take(self::RECENT_IMAGES)->values());
    }

    /** product_id -> {total: số bài kênh có ảnh sản phẩm, last_at} */
    private function usage(Channel $channel, array $productIds): Collection
    {
        if (! $productIds) {
            return collect();
        }

        return DB::table('social_post_images')
            ->join('social_posts', 'social_posts.id', '=', 'social_post_images.social_post_id')
            ->join('generations', 'generations.id', '=', 'social_post_images.generation_id')
            ->where('social_posts.channel_id', $channel->id)
            ->whereIn('generations.product_id', $productIds)
            ->groupBy('generations.product_id')
            ->selectRaw('generations.product_id, COUNT(DISTINCT social_posts.id) AS total, MAX(social_posts.scheduled_at) AS last_at')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * Sản phẩm của shop cần gắn tag: đang hoạt động, có ảnh trường TAG_FIELD_KEY, chưa có tag hoặc ảnh đó đã đổi so với
     * lúc gắn ($all: mọi sản phẩm có ảnh trường đó). cover = ảnh trường TAG_FIELD_KEY (ảnh skill xem).
     */
    public function untagged(User $shop, bool $all): array
    {
        return Product::query()->ownedBy($shop)->active()
            ->with(['images' => fn ($q) => $q->where('field_key', self::TAG_FIELD_KEY)])
            ->whereHas('images', fn ($q) => $q->where('field_key', self::TAG_FIELD_KEY))
            ->orderByDesc('id')->get()
            ->each(fn (Product $p) => $p->setRelation('coverImage', $p->images->first()))
            ->filter(fn (Product $p) => $all || ! $p->tags || $p->tagged_image_id !== $p->coverImage->id)
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'prompt_note' => $p->prompt_note,
                'cover' => ['image_id' => $p->coverImage->id, 'thumb_url' => $this->media->url($p->coverImage->thumbPathOrOriginal())],
                'ai_label' => $p->ai_label,
                'tags' => $p->tags,
                'tag_sources' => $p->tag_sources,
            ])->values()->all();
    }

    /**
     * Skill gửi tag AI cho nhiều sản phẩm của shop. Mỗi dòng {id, image_id (ảnh trường anh_san_pham đã xem), ai_label, tags} đủ trường.
     * Trường chủ shop đã sửa giữ nguyên (skipped). Dòng lỗi không lưu, dòng khác vẫn lưu.
     *
     * @return array{updated: int[], skipped: array<int, string[]>, errors: array<int, array{id: mixed, errors: string[]}>}
     */
    public function saveAi(User $shop, array $items): array
    {
        $products = Product::query()->ownedBy($shop)->with(['images' => fn ($q) => $q->select('id', 'product_id')->where('field_key', self::TAG_FIELD_KEY)])
            ->whereKey(collect($items)->pluck('id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all())
            ->get()->keyBy('id');
        $updated = [];
        $skipped = [];
        $errors = [];
        foreach ($items as $row) {
            $id = $row['id'] ?? null;
            $product = is_numeric($id) ? $products->get((int) $id) : null;
            if (! $product) {
                $errors[] = ['id' => $id, 'errors' => ['Sản phẩm không thuộc shop.']];

                continue;
            }
            $rowErrors = [];
            $label = ProductTags::cleanLabel($row['ai_label'] ?? null);
            if ($label === null || mb_strlen($label) > ProductTags::LABEL_MAX) {
                $rowErrors[] = 'ai_label bắt buộc, tối đa '.ProductTags::LABEL_MAX.' ký tự, không emoji.';
            }
            $raw = is_array($row['tags'] ?? null) ? $row['tags'] : [];
            [$tags, $tagErrors] = ProductTags::clean(array_intersect_key($raw, array_flip(ProductTags::tagFields())), ProductTags::tagFields());
            array_push($rowErrors, ...$tagErrors);
            foreach (array_diff(array_keys($raw), ProductTags::tagFields()) as $extra) {
                $rowErrors[] = "Không có trường tag \"{$extra}\".";
            }
            $imageId = (int) ($row['image_id'] ?? 0);
            if (! $product->images->contains('id', $imageId)) {
                $rowErrors[] = 'image_id phải là ảnh trường '.self::TAG_FIELD_KEY.' của sản phẩm (ảnh đã xem).';
            }
            if ($rowErrors) {
                $errors[] = ['id' => $product->id, 'errors' => $rowErrors];

                continue;
            }
            $skip = ProductTags::apply($product, ['ai_label' => $label, ...$tags], ProductTags::SOURCE_AI);
            $product->tagged_at = now();
            $product->tagged_image_id = $imageId;
            $product->save();
            $updated[] = $product->id;
            if ($skip) {
                $skipped[$product->id] = $skip;
            }
        }

        return ['updated' => $updated, 'skipped' => (object) $skipped, 'errors' => $errors];
    }
}
