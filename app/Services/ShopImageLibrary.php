<?php

namespace App\Services;

use App\Enums\OutputStatus;
use App\Models\GenerationOutput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Thư viện ảnh của shop để dựng video (API /video-images, bài đăng video): ảnh đã tạo xong, chưa bị tắt, nằm trong các lô
 * ảnh gắn sản phẩm của shop. Lô không gắn sản phẩm (tạo tay) không tính theo sản phẩm. Một ảnh nằm ở nhiều lô chỉ tính 1 lần.
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
        return DB::table('image_batch_images')
            ->join('image_batches', 'image_batches.id', '=', 'image_batch_images.image_batch_id')
            ->join('generation_outputs', 'generation_outputs.id', '=', 'image_batch_images.output_id')
            ->whereNotNull('image_batches.product_id')
            ->where('image_batches.user_id', $userId)
            ->where('generation_outputs.status', OutputStatus::Completed->value)
            ->whereNotNull('generation_outputs.image_path')
            ->whereNull('generation_outputs.disabled_at')
            ->groupBy('image_batches.product_id')
            ->selectRaw('image_batches.product_id, COUNT(DISTINCT image_batch_images.output_id) AS total')
            ->pluck('total', 'product_id')
            ->map(fn ($total) => (int) $total);
    }

    /** Ảnh dùng được của 1 sản phẩm trong các lô của shop. */
    public function productImages(int $userId, int $productId): Builder
    {
        return GenerationOutput::query()
            ->usable()
            ->whereIn('id', fn ($q) => $q->select('image_batch_images.output_id')
                ->from('image_batch_images')
                ->join('image_batches', 'image_batches.id', '=', 'image_batch_images.image_batch_id')
                ->where('image_batches.product_id', $productId)
                ->where('image_batches.user_id', $userId));
    }

    /** Mọi ảnh dùng được người dùng đã tạo (cả lô tạo tay, lịch sử tạo ảnh). */
    public function allImages(int $userId): Builder
    {
        return GenerationOutput::query()
            ->usable()
            ->whereHas('generation', fn ($q) => $q->where('user_id', $userId));
    }
}
