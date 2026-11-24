<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nhóm chủ đề (người dùng chốt 2026-11-24): chủ đề vẫn cụ thể ("Đi chúc Tết", "Tiệc cuối năm") nhưng thuộc 1 nhóm
 * (ChannelTopic::CATEGORIES: Sự kiện, Đi làm, Du lịch, Hằng ngày, Khác) để tab Chủ đề hiện theo nhóm. Chủ đề cũ: đoán nhóm
 * theo tên. Từ migration 130000 nhóm do chủ shop quản lý (bảng channel_topic_categories) - migration này tự chứa từ
 * khoá, không dùng model.
 */
return new class extends Migration
{
    private const WORDS = [
        'su_kien' => ['tết', 'tiệc', 'valentine', 'noel', 'giáng sinh', 'trung thu', '8/3', '20/10', '20/11', '14/2', 'cưới', 'sinh nhật',
            'lễ', 'mùng', 'du xuân', 'chơi xuân', 'kỷ niệm', 'black friday', 'sale', 'cuối năm', 'tất niên', 'halloween', 'tốt nghiệp'],
        'di_lam' => ['đi làm', 'công sở', 'văn phòng', 'phỏng vấn', 'họp', 'đi học'],
        'du_lich' => ['du lịch', 'đà lạt', 'biển', 'phượt', 'nghỉ dưỡng', 'sapa', 'hội an', 'phú quốc', 'vali', 'chuyến đi'],
        'hang_ngay' => ['hằng ngày', 'hàng ngày', 'đi chơi', 'cafe', 'cà phê', 'dạo phố', 'hẹn hò', 'cuối tuần', 'mua sắm', 'ở nhà'],
    ];

    private static function guess(string $text): string
    {
        $text = mb_strtolower($text);
        foreach (self::WORDS as $key => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $key;
                }
            }
        }

        return 'khac';
    }

    public function up(): void
    {
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->string('category', 20)->default('khac')->after('name');
        });
        DB::table('channel_topics')->orderBy('id')->each(function ($row) {
            DB::table('channel_topics')->where('id', $row->id)
                ->update(['category' => self::guess($row->name.' '.$row->note)]);
        });
    }

    public function down(): void
    {
        Schema::table('channel_topics', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
