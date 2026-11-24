<?php

use App\Models\ChannelTopic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nhóm chủ đề do chủ shop quản lý (người dùng chốt 2026-11-24, thay danh sách nhóm cố định của migration 120000): bảng
 * channel_topic_categories (theo kênh, có thứ tự), channel_topics.category_id (null = "Khác"). Kênh đã có chủ đề: tạo sẵn
 * các nhóm mặc định rồi chuyển nhóm cũ (khoá cố định) sang nhóm cùng tên; "khac" -> null.
 */
return new class extends Migration
{
    /** Khoá nhóm cố định cũ -> tên nhóm mặc định. */
    private const OLD = ['su_kien' => 'Sự kiện', 'di_lam' => 'Đi làm', 'du_lich' => 'Du lịch', 'hang_ngay' => 'Hằng ngày'];

    public function up(): void
    {
        Schema::create('channel_topic_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['channel_id', 'name']);
        });
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('name')->constrained('channel_topic_categories')->nullOnDelete();
        });

        $now = now();
        foreach (DB::table('channel_topics')->distinct()->pluck('channel_id') as $channelId) {
            $ids = [];
            foreach (ChannelTopic::DEFAULT_CATEGORIES as $i => $name) {
                $ids[$name] = DB::table('channel_topic_categories')->insertGetId([
                    'channel_id' => $channelId, 'name' => $name, 'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach (DB::table('channel_topics')->where('channel_id', $channelId)->get(['id', 'category']) as $topic) {
                $name = self::OLD[$topic->category] ?? null;
                DB::table('channel_topics')->where('id', $topic->id)->update(['category_id' => $name ? $ids[$name] : null]);
            }
        }

        Schema::table('channel_topics', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->string('category', 20)->default('khac')->after('name');
        });
        $old = array_flip(self::OLD);
        foreach (DB::table('channel_topics')->whereNotNull('category_id')->get(['id', 'category_id']) as $topic) {
            $name = DB::table('channel_topic_categories')->where('id', $topic->category_id)->value('name');
            DB::table('channel_topics')->where('id', $topic->id)->update(['category' => $old[$name] ?? 'khac']);
        }
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('channel_topic_categories');
    }
};
