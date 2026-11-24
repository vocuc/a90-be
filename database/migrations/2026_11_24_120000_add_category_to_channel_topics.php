<?php

use App\Models\ChannelTopic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nhóm chủ đề (người dùng chốt 2026-11-24): chủ đề vẫn cụ thể ("Đi chúc Tết", "Tiệc cuối năm") nhưng thuộc 1 nhóm
 * (ChannelTopic::CATEGORIES: Sự kiện, Đi làm, Du lịch, Hằng ngày, Khác) để tab Chủ đề hiện theo nhóm. Chủ đề cũ: đoán nhóm
 * theo tên (ChannelTopic::guessCategory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->string('category', 20)->default(ChannelTopic::DEFAULT_CATEGORY)->after('name');
        });
        DB::table('channel_topics')->orderBy('id')->each(function ($row) {
            DB::table('channel_topics')->where('id', $row->id)
                ->update(['category' => ChannelTopic::guessCategory($row->name.' '.$row->note)]);
        });
    }

    public function down(): void
    {
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
