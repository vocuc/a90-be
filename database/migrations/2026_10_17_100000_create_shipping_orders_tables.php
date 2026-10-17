<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo đơn GHN từ ảnh chụp màn hình (note/tao-don-ghn-tu-anh.txt ở repo frontend).
 * Token + ShopId GHN do shop tự nhập, lưu ở creator_profiles (token mã hoá, không trả về frontend).
 * Đơn nháp lưu cả id lẫn tên tỉnh / quận / phường: GHN tạo đơn theo tên, form sửa đơn chọn theo id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_profiles', function (Blueprint $table) {
            $table->text('ghn_token')->nullable();
            $table->unsignedBigInteger('ghn_shop_id')->nullable();
        });

        Schema::create('shipping_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('address_type', 10);
            $table->string('to_name')->nullable();
            $table->string('to_phone', 20)->nullable();
            $table->string('to_address', 500)->nullable();
            $table->unsignedBigInteger('to_province_id')->nullable();
            $table->string('to_province_name')->nullable();
            $table->unsignedBigInteger('to_district_id')->nullable();
            $table->string('to_district_name')->nullable();
            $table->string('to_ward_code', 20)->nullable();
            $table->string('to_ward_name')->nullable();
            $table->text('raw_address')->nullable();
            $table->string('item_name', 500);
            $table->unsignedInteger('cod_amount')->default(0);
            $table->unsignedInteger('weight');
            $table->string('required_note', 30);
            $table->unsignedTinyInteger('payment_type_id')->nullable();
            $table->text('note')->nullable();
            $table->json('low_confidence_fields')->nullable();
            $table->string('ghn_order_code', 50)->nullable();
            $table->unsignedInteger('shipping_fee')->nullable();
            $table->timestamp('expected_delivery_time')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shipping_order_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_order_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_order_images');
        Schema::dropIfExists('shipping_orders');
        Schema::table('creator_profiles', function (Blueprint $table) {
            $table->dropColumn(['ghn_token', 'ghn_shop_id']);
        });
    }
};
