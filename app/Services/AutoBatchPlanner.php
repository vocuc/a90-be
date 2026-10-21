<?php

namespace App\Services;

use App\Models\CreativeGroup;
use App\Models\Product;
use Closure;
use Illuminate\Support\Collection;

/**
 * Chọn lần lượt cặp sản phẩm x nhóm cho tạo lô tự động (AutoBatchService). Đếm lượt giữ trong bộ nhớ:
 * consume() sau mỗi lô đã tạo / đã xem trước để lô kế tiếp chọn đúng.
 */
final class AutoBatchPlanner
{
    /** @var array<int, int> lượt tự động theo product_id */
    private array $productCounts;

    /** @var array<string, ?array> creative dùng được theo "product:group" (null = chưa tính) */
    private array $items = [];

    /**
     * @param  Collection<int, Product>  $products  theo thứ tự tạo
     * @param  Collection<int, CreativeGroup>  $groups  theo thứ tự tạo
     * @param  array<string, int>  $pairCounts  lượt theo "product:group"
     * @param  Closure(Product, CreativeGroup): array  $resolveItems
     */
    public function __construct(
        private Collection $products,
        private Collection $groups,
        private array $pairCounts,
        private Closure $resolveItems,
    ) {
        $this->productCounts = $products->mapWithKeys(fn (Product $p) => [$p->id => (int) $p->auto_batch_count])->all();
    }

    /** @return array{product: Product, group: CreativeGroup, items: array, cost: int}|null */
    public function next(): ?array
    {
        // Theo lượt, bằng nhau thì cái tạo trước (id nhỏ hơn)
        $products = $this->products->sort(fn (Product $a, Product $b) => [$this->productCounts[$a->id], $a->id] <=> [$this->productCounts[$b->id], $b->id]);

        foreach ($products as $product) {
            // Nhóm sản phẩm này chạy ít nhất, bằng nhau thì ngẫu nhiên
            $groups = $this->groups->sort(fn (CreativeGroup $a, CreativeGroup $b) => [$this->pairCount($product, $a), $this->shuffle($product, $a), $a->id] <=> [$this->pairCount($product, $b), $this->shuffle($product, $b), $b->id]);
            foreach ($groups as $group) {
                $key = "{$product->id}:{$group->id}";
                $this->items[$key] ??= ($this->resolveItems)($product, $group);
                if ($this->items[$key]) {
                    $items = $this->items[$key];

                    return [
                        'product' => $product,
                        'group' => $group,
                        'items' => $items,
                        'cost' => array_sum(array_map(fn (array $item) => $item['creative']->price_per_image * $item['quantity'], $items)),
                    ];
                }
            }
        }

        return null;
    }

    /** @param  array{product: Product, group: CreativeGroup}  $plan */
    public function consume(array $plan): void
    {
        $this->productCounts[$plan['product']->id]++;
        $key = "{$plan['product']->id}:{$plan['group']->id}";
        $this->pairCounts[$key] = ($this->pairCounts[$key] ?? 0) + 1;
    }

    private function pairCount(Product $product, CreativeGroup $group): int
    {
        return $this->pairCounts["{$product->id}:{$group->id}"] ?? 0;
    }

    /**
     * Thứ tự ngẫu nhiên giữa các nhóm bằng lượt: băm theo sản phẩm, nhóm và lượt hiện tại của sản phẩm nên đổi sau
     * mỗi lô của sản phẩm, nhưng cùng dữ liệu thì xem trước và tạo thật chọn cùng nhóm (tổng tiền xem trước đúng).
     */
    private function shuffle(Product $product, CreativeGroup $group): int
    {
        return (int) hexdec(substr(md5("{$product->id}:{$group->id}:{$this->productCounts[$product->id]}"), 0, 7));
    }
}
