<?php

namespace App\Services;

use App\Models\CreativeGroup;
use App\Models\Product;
use Closure;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Chọn cặp sản phẩm x nhóm cho tạo lô tự động (AutoBatchService): xáo ngẫu nhiên sản phẩm và nhóm theo seed rồi ghép
 * lần lượt, mỗi sản phẩm / nhóm dùng tối đa một lần. Cùng seed + cùng dữ liệu thì ra cùng kết quả (xem trước = tạo thật).
 */
final class AutoBatchPlanner
{
    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, CreativeGroup>  $groups
     * @param  Closure(Product, CreativeGroup): array  $resolveItems
     */
    public function __construct(
        private Collection $products,
        private Collection $groups,
        private Closure $resolveItems,
    ) {}

    /**
     * Tối đa $count cặp, ít hơn khi hết sản phẩm / nhóm. Sản phẩm thứ i ghép nhóm thứ i; cặp không có creative dùng được
     * thì thử nhóm chưa dùng kế tiếp, không nhóm nào được thì bỏ sản phẩm.
     *
     * @return array<int, array{product: Product, group: CreativeGroup, items: array, cost: int}>
     */
    public function plan(int $count, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $products = $random->shuffleArray($this->products->values()->all());
        $groups = $random->shuffleArray($this->groups->values()->all());
        $plans = [];

        foreach ($products as $product) {
            if (count($plans) >= $count || ! $groups) {
                break;
            }
            foreach ($groups as $i => $group) {
                if ($items = ($this->resolveItems)($product, $group)) {
                    unset($groups[$i]);
                    $plans[] = [
                        'product' => $product,
                        'group' => $group,
                        'items' => $items,
                        'cost' => array_sum(array_map(fn (array $item) => $item['creative']->price_per_image * $item['quantity'], $items)),
                    ];
                    break;
                }
            }
        }

        return $plans;
    }
}
