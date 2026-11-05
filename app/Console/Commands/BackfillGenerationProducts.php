<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Điền generations.product_id cho ảnh tạo trước khi có cột (migration 2026_11_05_100000).
 * 1. Generation đang nằm trong lô tự động: lấy image_batches.product_id.
 * 2. Generation được tạo lại / sửa nguyên liệu từ generation đã có sản phẩm (parent_generation_id): lấy theo cha,
 *    lặp tới khi không còn dòng nào đổi (bắt được cả chuỗi tạo lại nhiều lần).
 * Chỉ ghi vào dòng đang là 0, chạy lại nhiều lần không sao.
 */
class BackfillGenerationProducts extends Command
{
    protected $signature = 'generations:backfill-product {--dry-run : Chỉ đếm, không ghi}';

    protected $description = 'Điền product_id cho các generation thuộc lô tự động cũ';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Bước 1: từ lô tự động
        $pairs = DB::table('image_batch_images as i')
            ->join('image_batches as b', 'b.id', '=', 'i.image_batch_id')
            ->join('generations as g', 'g.id', '=', 'i.generation_id')
            ->whereNotNull('b.product_id')
            ->where('g.product_id', 0)
            ->distinct()
            ->pluck('b.product_id', 'i.generation_id');

        $fromBatches = $this->apply($pairs->all(), $dry);
        $this->info("Từ lô tự động: {$fromBatches} generation.");

        // Bước 2: lan theo generation cha
        $fromParents = 0;
        do {
            $pairs = DB::table('generations as g')
                ->join('generations as p', 'p.id', '=', 'g.parent_generation_id')
                ->where('g.product_id', 0)
                ->where('p.product_id', '>', 0)
                ->pluck('p.product_id', 'g.id');

            $changed = $this->apply($pairs->all(), $dry);
            $fromParents += $changed;
        } while ($changed > 0 && ! $dry);

        $this->info("Theo generation cha: {$fromParents} generation.");
        if ($dry) {
            $this->warn('Dry run: chưa ghi gì (bước 2 chỉ đếm được một tầng).');
        }

        return self::SUCCESS;
    }

    /** @param  array<int, int>  $pairs  generation_id => product_id */
    private function apply(array $pairs, bool $dry): int
    {
        if ($dry) {
            return count($pairs);
        }

        $count = 0;
        // Gom theo sản phẩm để mỗi sản phẩm một câu UPDATE
        $byProduct = [];
        foreach ($pairs as $generationId => $productId) {
            $byProduct[$productId][] = $generationId;
        }

        foreach ($byProduct as $productId => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $count += DB::table('generations')
                    ->whereIn('id', $chunk)
                    ->where('product_id', 0)
                    ->update(['product_id' => $productId]);
            }
        }

        return $count;
    }
}
