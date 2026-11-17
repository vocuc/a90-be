<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template prompt (2026-11-18): Creator ghép các block (tiêu đề + nội dung) thành prompt dùng chung cho nhiều Creative.
 * Creative chọn Template: prompt_template = prompt của Template + own_prompt_template (prompt riêng của Creative).
 * Cột prompt_template vẫn là bản đã gộp để tạo ảnh không phải đổi gì.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 150);
            // [{ title, content }] theo thứ tự ghép
            $table->json('blocks');
            $table->timestamps();
        });

        Schema::table('ai_creative_configs', function (Blueprint $table) {
            // Template đang có Creative dùng thì không xóa được (PromptTemplateController::destroy)
            $table->foreignId('prompt_template_id')->nullable()->after('creative_id')->constrained('prompt_templates')->restrictOnDelete();
            // Prompt riêng của Creative; null = Creative cũ, prompt_template là của riêng nó
            $table->text('own_prompt_template')->nullable()->after('prompt_template');
        });
    }

    public function down(): void
    {
        Schema::table('ai_creative_configs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prompt_template_id');
            $table->dropColumn('own_prompt_template');
        });
        Schema::dropIfExists('prompt_templates');
    }
};
