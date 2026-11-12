<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kế hoạch nội dung (FlowKit a51-skills/ke-hoach-noi-dung/thiet-ke.txt mục 9):
 *  - plan_contexts: bối cảnh cố định chủ shop quy ước (mô tả + tỉ lệ khung + model), 1 hoặc nhiều / tài khoản.
 *  - social_posts.script / plan_context_id: kịch bản của bài kế hoạch (source = plan) và bối cảnh dùng tạo ảnh.
 *  - generations.creative_id cho phép null: ảnh của bài kế hoạch (type plan) không dùng creative,
 *    prompt = mô tả bối cảnh + câu lệnh planner viết.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description');
            $table->string('aspect_ratio', 10)->default('9:16');
            $table->foreignId('model_id')->constrained('ai_models');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->json('script')->nullable()->after('source');
            $table->foreignId('plan_context_id')->nullable()->after('script')->constrained('plan_contexts')->nullOnDelete();
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedBigInteger('creative_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_context_id');
            $table->dropColumn('script');
        });

        Schema::dropIfExists('plan_contexts');
        // generations.creative_id giữ nullable: đã có ảnh kế hoạch thì không đổi lại NOT NULL được
    }
};
