<?php

namespace App\Services;

use App\Enums\OutputStatus;
use App\Models\Generation;
use App\Models\GenerationOutput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Thư viện ảnh của shop để dựng video (API /video-images, bài đăng video): ảnh đã tạo xong, chưa bị tắt.
 * Theo sản phẩm: ảnh có generations.product_id = sản phẩm (lô tự động, lô tạo tay / tạo ảnh lẻ có chọn sản phẩm).
 * Chỉ ảnh creative: ảnh phối đồ (lô Phối đồ, generations.kind outfit_cover / outfit) không dùng để dựng video
 * (yêu cầu người dùng 2026-11-15).
 */
class ShopImageLibrary
{
    /**
     * Số ảnh dùng được của từng sản phẩm ĐANG HOẠT ĐỘNG (dùng để chọn sản phẩm ngẫu nhiên: tạo bài video tự động,
     * API ảnh cho worker). Sản phẩm đã tắt hoặc đã xoá không được chọn.
     *
     * @return Collection<int, int> product_id => số ảnh
     */
    public function countsPerProduct(int $userId): Collection
    {
        return DB::table('generation_outputs')
            ->join('generations', 'generations.id', '=', 'generation_outputs.generation_id')
            ->join('products', 'products.id', '=', 'generations.product_id')
            ->where('products.is_active', true)
            ->where('generations.user_id', $userId)
            ->where('generations.product_id', '>', 0)
            ->where('generations.kind', Generation::KIND_CREATIVE)
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
            ->whereHas('generation', fn ($q) => $q->where('user_id', $userId)->where('product_id', $productId)
                ->where('kind', Generation::KIND_CREATIVE));
    }

    /**
     * Ảnh cho bài video tạo tự động (yêu cầu người dùng 2026-11-16): ảnh chưa dùng trong bài video nào trước, mới nhất
     * trước; không đủ mới lấy ảnh đã dùng (dùng ít lần trước, cùng số lần thì mới nhất trước).
     * "Đã dùng" = nằm trong bài video bất kỳ còn tồn tại (cả bài đang chờ đăng), nên các bài tạo trong cùng một lần bấm
     * không lặp ảnh khi sản phẩm còn đủ ảnh chưa dùng.
     *
     * @return Collection<int, GenerationOutput>
     */
    public function productImagesForVideo(int $userId, int $productId, int $limit): Collection
    {
        $uses = DB::table('social_post_images')
            ->join('social_posts', 'social_posts.id', '=', 'social_post_images.social_post_id')
            ->whereColumn('social_post_images.output_id', 'generation_outputs.id')
            ->where('social_posts.type', 'video')
            ->selectRaw('COUNT(*)');

        return $this->productImages($userId, $productId)
            ->select('generation_outputs.*')
            ->selectSub($uses, 'video_uses')
            ->orderBy('video_uses')
            ->orderByDesc('generation_outputs.created_at')
            ->orderByDesc('generation_outputs.id')
            ->limit($limit)
            ->get();
    }

    /** Mọi ảnh creative dùng được người dùng đã tạo (cả lô tạo tay, lịch sử tạo ảnh). */
    public function allImages(int $userId): Builder
    {
        return GenerationOutput::query()
            ->usable()
            ->whereHas('generation', fn ($q) => $q->where('user_id', $userId)->where('kind', Generation::KIND_CREATIVE));
    }
}
