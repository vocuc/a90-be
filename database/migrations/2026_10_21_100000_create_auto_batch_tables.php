<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo lô tự động (note/auto-tao-lo.txt ở repo frontend): sản phẩm của Creator (ảnh liên kết tới key trường nguyên liệu),
 * nhóm creative (một creative chỉ ở một nhóm), bảng đếm số lần chạy tự động theo sản phẩm x nhóm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            // Số lần chạy tự động của sản phẩm (xoá lô / xoá nhóm không giảm), sản phẩm ít nhất được tạo trước
            $table->unsignedInteger('auto_batch_count')->default(0);
            $table->timestamps();
        });

        // File ảnh không xoá khi sửa / xoá sản phẩm: lượt tạo ảnh cũ dùng thẳng đường dẫn này (tạo lại, sửa nguyên liệu)
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 64);
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'field_key']);
        });

        Schema::create('creative_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('creative_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creative_id')->unique()->constrained('ai_creatives')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('auto_batch_counts', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creative_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count')->default(0);
            $table->primary(['product_id', 'creative_group_id']);
        });

        Schema::table('image_batches', function (Blueprint $table) {
            $table->string('source', 16)->default('manual')->after('user_id');
            $table->foreignId('product_id')->nullable()->after('source')->constrained()->nullOnDelete();
            $table->foreignId('creative_group_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creative_group_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('source');
        });
        Schema::dropIfExists('auto_batch_counts');
        Schema::dropIfExists('creative_group_items');
        Schema::dropIfExists('creative_groups');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }
};
