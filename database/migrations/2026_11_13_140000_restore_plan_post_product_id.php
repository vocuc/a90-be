<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bấm "Đăng" bài kế hoạch dạng ảnh từng xoá product_id. Lấy lại từ lượt tạo ảnh của bài (generations.product_id) để
 * "Làm lại" và xoay vòng sản phẩm của planner dùng được.
 */
return new class extends Migration
{
    public function up(): void
    {
        $posts = DB::table('social_posts')->where('source', 'plan')->whereNull('product_id')->pluck('id');
        foreach ($posts as $postId) {
            $productId = DB::table('social_post_images')
                ->join('generations', 'generations.id', '=', 'social_post_images.generation_id')
                ->where('social_post_images.social_post_id', $postId)
                ->whereNotNull('generations.product_id')
                ->value('generations.product_id');
            if ($productId) {
                DB::table('social_posts')->where('id', $postId)->update(['product_id' => $productId]);
            }
        }
    }

    public function down(): void {}
};
