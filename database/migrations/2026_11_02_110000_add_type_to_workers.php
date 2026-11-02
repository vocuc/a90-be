<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Worker có loại (note/quan-ly-worker.txt ở repo frontend): image (nhận job tạo ảnh) | publish (đăng bài ảnh / video,
 * lấy ảnh tạo video, đồng bộ template). Cả 2 loại gắn nhiều tài khoản A51 qua worker_user; một tài khoản có nhiều worker
 * cùng loại. Worker cũ: chuyển hết sang publish (admin tự đổi lại loại), tài khoản gắn = tài khoản tạo ảnh + shop đăng
 * bài (video_user_id) cũ; bỏ cột video_user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('type', 20)->default('publish')->after('name');
        });

        DB::table('workers')->whereNotNull('video_user_id')->orderBy('id')->each(function ($worker) {
            DB::table('worker_user')->insertOrIgnore([
                'worker_id' => $worker->id,
                'user_id' => $worker->video_user_id,
                'created_at' => now(),
            ]);
        });

        Schema::table('workers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->foreignId('video_user_id')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
        });
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
