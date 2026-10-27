<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Một tài khoản A51 kết nối nhiều tài khoản TikTok (note/uy-quyen-nhieu-tiktok.txt ở repo frontend):
 * - publish_accounts: dòng api phân biệt theo open_id (unique user_id, platform, driver, open_id). Dòng worker có open_id
 *   null nên MySQL không chặn trùng: "1 dòng worker / nền tảng" vẫn do admin kiểm tra như cũ.
 * - social_publish_jobs: một bài gửi vào nhiều tài khoản TikTok một lượt -> nhiều job TikTok. Mỗi job lưu đích gửi
 *   (account_open_id, account_name: giữ lại khi tài khoản bị huỷ kết nối). superseded: job lỗi của lần đăng trước,
 *   không tính vào trạng thái nền tảng nữa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_accounts', function (Blueprint $table) {
            // Index mới có user_id đứng đầu nên khoá ngoại user_id vẫn có index để dùng khi bỏ index cũ
            $table->unique(['user_id', 'platform', 'driver', 'open_id']);
            $table->dropUnique(['user_id', 'platform', 'driver']);
        });

        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->string('account_open_id', 100)->nullable()->after('publish_account_id');
            $table->string('account_name', 150)->nullable()->after('account_open_id');
            $table->boolean('superseded')->default(false)->after('account_name');
        });

        // Job api cũ: chép đích gửi từ tài khoản
        DB::table('social_publish_jobs')
            ->where('driver', 'api')
            ->whereNotNull('publish_account_id')
            ->orderBy('id')
            ->each(function ($job) {
                $account = DB::table('publish_accounts')->find($job->publish_account_id);
                if ($account) {
                    DB::table('social_publish_jobs')->where('id', $job->id)->update([
                        'account_open_id' => $account->open_id,
                        'account_name' => $account->username ? '@'.$account->username : $account->name,
                    ]);
                }
            });

        // Trước đây trạng thái nền tảng chỉ theo job mới nhất: job lỗi cũ hơn coi như đã bị thay
        $latest = DB::table('social_publish_jobs')
            ->selectRaw('MAX(id) as id')
            ->groupBy('social_post_id', 'platform')
            ->pluck('id');
        DB::table('social_publish_jobs')
            ->where('status', 'failed')
            ->whereNotIn('id', $latest)
            ->update(['superseded' => true]);
    }

    public function down(): void
    {
        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->dropColumn(['account_open_id', 'account_name', 'superseded']);
        });

        Schema::table('publish_accounts', function (Blueprint $table) {
            $table->unique(['user_id', 'platform', 'driver']);
            $table->dropUnique(['user_id', 'platform', 'driver', 'open_id']);
        });
    }
};
