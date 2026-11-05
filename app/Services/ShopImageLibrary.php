<?php

namespace App\Services;

use App\Enums\OutputStatus;
use App\Models\GenerationOutput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Thư viện ảnh của shop để dựng video (API /video-images, bài đăng video): ảnh đã tạo xong, chưa bị tắt.
 * Theo sản phẩm: ảnh có generations.product_id = sản phẩm (lô tự động, lô tạo tay / tạo ảnh lẻ có chọn sản phẩm).
 */
class ShopImageLibrary
{
    /**
     * Số ảnh dùng được của từng sản phẩm.
     *
     * @return Collection<int, int> product_id => số ảnh
     */
    public function countsPerProduct(int $userId): Collection
    {
        return DB::table('generation_outputs')
            ->join('generations', 'generations.id', '=', 'generation_outputs.generation_id')
            ->where('generations.user_id', $userId)
            ->where('generations.product_id', '>', 0)
            ->where('generation_outputs.status', OutputStatus::Completed->value)
            ->whereNotNull('generation_outputs.image_path')
            ->whereNull('generation_outputs.disabled_at')
            ->groupBy('generations.product_id')
            ->selectRaw('generations.product_id, COUNT(*) AS total')
            ->pluck('total', 'product_id')
            ->map(fn ($total) => (int) $total);
    }

    /** Ảnh dùng được của 1 sản phẩm của shop. */
    public function productImages(int $userId, int $productId): Builder
    {
        return GenerationOutput::query()
            ->usable()
            ->whereHas('generation', fn ($q) => $q->where('user_id', $userId)->where('product_id', $productId));
    }

    /** Mọi ảnh dùng được người dùng đã tạo (cả lô tạo tay, lịch sử tạo ảnh). */
    public function allImages(int $userId): Builder
    {
        return GenerationOutput::query()
            ->usable()
            ->whereHas('generation', fn ($q) => $q->where('user_id', $userId));
    }
}
