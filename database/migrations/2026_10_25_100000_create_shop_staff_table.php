<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nhân viên của shop (note/chia-se-tai-nguyen.txt ở repo frontend): nhân viên dùng sản phẩm / nhóm / creative của shop
 * chủ, tiền trừ ví nhân viên. Lượt tạo lô tự động đếm riêng từng người (nhân viên dùng chung sản phẩm / nhóm của shop).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Mỗi người chỉ thuộc một shop (unique user_id)
        Schema::create('shop_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // Logo chèn vào ảnh của nhân viên (nhân viên không có shop nên không có ảnh đại diện shop)
        Schema::table('users', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('password');
        });

        // Lô có dùng tài nguyên của shop: chủ shop xem được (kể cả sau khi gỡ nhân viên), nhân viên bị gỡ không tạo lại được
        Schema::table('image_batches', function (Blueprint $table) {
            $table->foreignId('shop_owner_id')->nullable()->after('creative_group_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('auto_batch_product_counts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count')->default(0);
            $table->primary(['user_id', 'product_id']);
        });
        DB::statement('INSERT INTO auto_batch_product_counts (user_id, product_id, count)
            SELECT user_id, id, auto_batch_count FROM products WHERE auto_batch_count > 0');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('auto_batch_count');
        });

        // Khoá chính cũ đang là index của khoá ngoại product_id: thêm index riêng trước khi đổi
        Schema::table('auto_batch_counts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->first();
            $table->index('product_id');
        });
        DB::statement('UPDATE auto_batch_counts c JOIN products p ON p.id = c.product_id SET c.user_id = p.user_id');
        Schema::table('auto_batch_counts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->dropPrimary();
            $table->primary(['user_id', 'product_id', 'creative_group_id']);
        });
    }

    public function down(): void
    {
        // Chỉ giữ lượt của chủ sản phẩm
        DB::statement('DELETE c FROM auto_batch_counts c JOIN products p ON p.id = c.product_id WHERE c.user_id <> p.user_id');
        Schema::table('auto_batch_counts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropPrimary();
            $table->primary(['product_id', 'creative_group_id']);
            $table->dropIndex(['product_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('auto_batch_count')->default(0)->after('name');
        });
        DB::statement('UPDATE products p JOIN auto_batch_product_counts c ON c.product_id = p.id AND c.user_id = p.user_id
            SET p.auto_batch_count = c.count');
        Schema::dropIfExists('auto_batch_product_counts');

        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_owner_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
        Schema::dropIfExists('shop_staff');
    }
};
