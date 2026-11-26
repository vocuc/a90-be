<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ảnh / lô tạo cho kênh nào (2026-11-26, lọc Lịch sử tạo ảnh theo kênh). null = không rõ kênh (tạo lẻ, lô tay không chọn
 * kênh, ảnh cũ không đoán được). Xoá kênh thì về null (nullOnDelete).
 *
 * Điền cho dữ liệu cũ, ưu tiên từ chắc chắn nhất, bước sau chỉ điền chỗ còn trống:
 *  1. ảnh đã gắn vào bài đăng có kênh (social_post_images -> social_posts.channel_id)
 *  2. ảnh thuộc lô mà bài đăng có kênh đã dùng (social_posts.image_batch_id)
 *  3. ảnh thuộc lô tự động, nhóm creative của lô có kênh (image_batches.creative_group_id -> creative_groups.channel_id)
 *  4. creative tạo ra ảnh đang ở nhóm có kênh (creative_group_items -> creative_groups.channel_id)
 *  Lô: từ bài đăng dùng lô, rồi nhóm creative của lô, rồi kênh của ảnh đầu tiên trong lô.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
        Schema::table('image_batches', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        // 1. Ảnh của bài đăng có kênh
        DB::statement('UPDATE generations g
            JOIN (SELECT spi.generation_id, MIN(sp.channel_id) AS channel_id
                  FROM social_post_images spi JOIN social_posts sp ON sp.id = spi.social_post_id
                  WHERE sp.channel_id IS NOT NULL GROUP BY spi.generation_id) x ON x.generation_id = g.id
            SET g.channel_id = x.channel_id WHERE g.channel_id IS NULL');

        // 2. Ảnh thuộc lô mà bài đăng có kênh đã dùng
        DB::statement('UPDATE generations g
            JOIN (SELECT ibi.generation_id, MIN(sp.channel_id) AS channel_id
                  FROM image_batch_images ibi JOIN social_posts sp ON sp.image_batch_id = ibi.image_batch_id
                  WHERE sp.channel_id IS NOT NULL GROUP BY ibi.generation_id) x ON x.generation_id = g.id
            SET g.channel_id = x.channel_id WHERE g.channel_id IS NULL');

        // 3. Ảnh thuộc lô tự động có nhóm creative riêng của kênh
        DB::statement('UPDATE generations g
            JOIN (SELECT ibi.generation_id, MIN(cg.channel_id) AS channel_id
                  FROM image_batch_images ibi
                  JOIN image_batches ib ON ib.id = ibi.image_batch_id
                  JOIN creative_groups cg ON cg.id = ib.creative_group_id
                  WHERE cg.channel_id IS NOT NULL GROUP BY ibi.generation_id) x ON x.generation_id = g.id
            SET g.channel_id = x.channel_id WHERE g.channel_id IS NULL');

        // 4. Creative tạo ra ảnh đang ở nhóm riêng của kênh (mỗi creative tối đa 1 nhóm)
        DB::statement('UPDATE generations g
            JOIN creative_group_items cgi ON cgi.creative_id = g.creative_id
            JOIN creative_groups cg ON cg.id = cgi.creative_group_id
            SET g.channel_id = cg.channel_id
            WHERE g.channel_id IS NULL AND cg.channel_id IS NOT NULL');

        // Lô
        DB::statement('UPDATE image_batches ib
            JOIN (SELECT image_batch_id, MIN(channel_id) AS channel_id FROM social_posts
                  WHERE image_batch_id IS NOT NULL AND channel_id IS NOT NULL GROUP BY image_batch_id) x ON x.image_batch_id = ib.id
            SET ib.channel_id = x.channel_id WHERE ib.channel_id IS NULL');
        DB::statement('UPDATE image_batches ib
            JOIN creative_groups cg ON cg.id = ib.creative_group_id
            SET ib.channel_id = cg.channel_id
            WHERE ib.channel_id IS NULL AND cg.channel_id IS NOT NULL');
        DB::statement('UPDATE image_batches ib
            JOIN (SELECT ibi.image_batch_id, MIN(g.channel_id) AS channel_id
                  FROM image_batch_images ibi JOIN generations g ON g.id = ibi.generation_id
                  WHERE g.channel_id IS NOT NULL GROUP BY ibi.image_batch_id) x ON x.image_batch_id = ib.id
            SET ib.channel_id = x.channel_id WHERE ib.channel_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
        });
        Schema::table('generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
        });
    }
};
