<?php

namespace App\Services;

use App\Models\CreativeGroup;
use App\Models\Product;
use Closure;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Chọn cặp sản phẩm x nhóm cho tạo lô tự động (AutoBatchService), mỗi sản phẩm / nhóm dùng tối đa một lần:
 * - Sản phẩm: ít ảnh đã tạo nhất đi trước, bằng nhau thì ngẫu nhiên.
 * - Với từng sản phẩm: nhóm chưa dùng có tổng lượt tạo (của các creative sẽ dùng trong nhóm, với đúng sản phẩm đó) ít nhất,
 *   bằng nhau thì ngẫu nhiên. Nhóm không có creative dùng được với sản phẩm thì bỏ qua.
 * Ngẫu nhiên theo seed: cùng seed + cùng dữ liệu thì ra cùng kết quả (xem trước = tạo thật).
 */
final class AutoBatchPlanner
{
    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, CreativeGroup>  $groups
     * @param  Closure(Product, CreativeGroup): array  $resolveItems
     * @param  array<int, array<int, int>>  $counts  lượt tạo [product_id][creative_id] => số ảnh (thiếu = 0)
     */
    public function __construct(
        private Collection $products,
        private Collection $groups,
        private Closure $resolveItems,
        private array $counts = [],
    ) {}

    /**
     * Tối đa $count cặp, ít hơn khi hết sản phẩm / nhóm. Sản phẩm không ghép được nhóm nào còn lại thì bỏ qua.
     *
     * @return array<int, array{product: Product, group: CreativeGroup, items: array}>
     */
    public function plan(int $count, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        // Xáo trước rồi sắp xếp ổn định theo số ảnh: bằng nhau thì giữ thứ tự ngẫu nhiên
        $products = $random->shuffleArray($this->products->values()->all());
        usort($products, fn (Product $a, Product $b) => $this->productCount($a) <=> $this->productCount($b));
        $groups = $random->shuffleArray($this->groups->values()->all());
        $plans = [];

        foreach ($products as $product) {
            if (count($plans) >= $count || ! $groups) {
                break;
            }

            $best = null;
            foreach ($groups as $i => $group) {
                if (! $items = ($this->resolveItems)($product, $group)) {
                    continue;
                }
                $score = array_sum(array_map(fn (array $item) => $this->counts[$product->id][$item['creative']->id] ?? 0, $items));
                // Chỉ thay khi ít hơn hẳn: bằng nhau thì giữ nhóm đứng trước trong thứ tự đã xáo
                if ($best === null || $score < $best['score']) {
                    $best = ['index' => $i, 'group' => $group, 'items' => $items, 'score' => $score];
                }
            }
            if (! $best) {
                continue;
            }

            unset($groups[$best['index']]);
            $plans[] = [
                'product' => $product,
                'group' => $best['group'],
                'items' => $best['items'],
            ];
        }

        return $plans;
    }

    /** Tổng số ảnh đã tạo của sản phẩm (mọi creative). */
    private function productCount(Product $product): int
    {
        return array_sum($this->counts[$product->id] ?? []);
    }
}
