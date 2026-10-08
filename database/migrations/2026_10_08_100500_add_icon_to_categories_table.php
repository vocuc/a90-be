<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Icon mặc định cho các danh mục do CategorySeeder tạo (tên icon Material Symbols). */
    private const DEFAULT_ICONS = [
        'thoi-trang' => 'styler',
        'my-pham' => 'spa',
        'do-an-do-uong' => 'restaurant',
        'noi-that' => 'chair',
        'dien-tu' => 'devices',
        'trang-suc' => 'diamond',
        'khac' => 'category',
    ];

    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Tên icon Material Symbols, frontend hiển thị bằng font Material Symbols Outlined
            $table->string('icon', 50)->nullable()->after('slug');
        });

        // Gán icon cho các danh mục đã seed trước khi có cột này
        foreach (self::DEFAULT_ICONS as $slug => $icon) {
            DB::table('categories')->where('slug', $slug)->whereNull('icon')->update(['icon' => $icon]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
