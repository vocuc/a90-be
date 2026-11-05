<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sản phẩm của ảnh chỉ lưu ở generations.product_id (0 = không gắn sản phẩm), bỏ image_batches.product_id.
 * Trước khi xoá: chép sản phẩm của lô sang generations của lô (chỉ dòng đang là 0), rồi lan sang các generation
 * tạo lại / sửa nguyên liệu từ đó (parent_generation_id), lặp tới khi không còn dòng nào đổi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pairs = DB::table('image_batch_images as i')
            ->join('image_batches as b', 'b.id', '=', 'i.image_batch_id')
            ->join('generations as g', 'g.id', '=', 'i.generation_id')
            ->whereNotNull('b.product_id')
            ->where('g.product_id', 0)
            ->distinct()
            ->pluck('b.product_id', 'i.generation_id')
            ->all();
        $this->apply($pairs);

        do {
            $pairs = DB::table('generations as g')
                ->join('generations as p', 'p.id', '=', 'g.parent_generation_id')
                ->where('g.product_id', 0)
                ->where('p.product_id', '>', 0)
                ->pluck('p.product_id', 'g.id')
                ->all();
        } while ($this->apply($pairs) > 0);

        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('source')->constrained()->nullOnDelete();
        });

        // Lô tự động: lấy lại sản phẩm từ generations của lô
        DB::table('image_batches')->where('source', 'auto')->orderBy('id')->each(function ($batch) {
            $productId = DB::table('image_batch_images as i')
                ->join('generations as g', 'g.id', '=', 'i.generation_id')
                ->join('products as p', 'p.id', '=', 'g.product_id')
                ->where('i.image_batch_id', $batch->id)
                ->orderBy('i.position')
                ->value('g.product_id');
            if ($productId) {
                DB::table('image_batches')->where('id', $batch->id)->update(['product_id' => $productId]);
            }
        });
    }

    /** @param  array<int, int>  $pairs  generation_id => product_id */
    private function apply(array $pairs): int
    {
        $byProduct = [];
        foreach ($pairs as $generationId => $productId) {
            $byProduct[$productId][] = $generationId;
        }

        $count = 0;
        foreach ($byProduct as $productId => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $count += DB::table('generations')->whereIn('id', $chunk)->where('product_id', 0)->update(['product_id' => $productId]);
            }
        }

        return $count;
    }
};
