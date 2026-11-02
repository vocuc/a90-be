<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gộp luồng đăng bài ảnh + video (note/gop-luong-dang-bai.txt ở repo frontend):
 * - Mọi bài giao cho 1 worker (social_posts.worker_id), có giờ đăng (scheduled_at), 1 job / bài chứa mọi nền tảng đã chọn
 *   (social_publish_jobs.platforms). Worker đăng bằng tài khoản nào tự lo, backend không giữ tài khoản đăng bài nữa.
 * - Bỏ TikTok tự kết nối (OAuth) và bảng publish_accounts: xoá dữ liệu.
 * - Worker gắn cho shop để đăng bài = workers.video_user_id ("Shop đăng bài"): chép từ publish_accounts trước khi xoá.
 * - Bài ảnh đang chờ / đang đăng theo luồng cũ (chưa có worker): về chờ gửi để chủ shop chọn worker rồi đăng lại.
 * - Bài video đang chờ (chưa có nền tảng): đăng lên nền tảng tài khoản worker cũ giữ, không có thì TikTok.
 * Không hoàn tác được (dữ liệu TikTok / tài khoản đăng bài đã xoá).
 */
return new class extends Migration
{
    private const MIGRATED_ERROR = [
        'code' => 'WORKER_REQUIRED',
        'message' => 'A51 đã đổi cách đăng bài: chọn worker rồi bấm đăng lại.',
        'platforms' => [],
    ];

    public function up(): void
    {
        // Nền tảng worker cũ giữ (để gán cho bài video đang chờ của worker đó)
        $workerPlatform = DB::table('publish_accounts')
            ->where('driver', 'worker')
            ->whereNotNull('worker_id')
            ->pluck('platform', 'worker_id');

        // 1. Worker giữ tài khoản đăng bài của shop -> worker đăng bài cho shop đó (chỉ khi chưa gắn shop tạo video)
        DB::table('publish_accounts')
            ->where('driver', 'worker')
            ->whereNotNull('worker_id')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->each(function ($account) {
                DB::table('workers')->where('id', $account->worker_id)->whereNull('video_user_id')
                    ->update(['video_user_id' => $account->user_id]);
            });

        // 2. Job chưa xong của luồng cũ: bỏ (bài ảnh về chờ gửi, bài video chờ worker nhận lại)
        DB::table('social_publish_jobs')->whereIn('status', ['queued', 'processing'])->update([
            'status' => 'failed',
            'superseded' => true,
            'error_code' => 'MIGRATED',
            'error_message' => 'Đổi sang luồng đăng bài mới.',
            'finished_at' => now(),
        ]);

        // Bài ảnh đang chờ / đang đăng: các nền tảng chưa xong bỏ chọn, bài về chờ gửi
        foreach (['tiktok', 'facebook', 'instagram'] as $platform) {
            DB::table('social_posts')
                ->where('type', 'photo')
                ->whereIn('status', ['queued', 'publishing'])
                ->whereIn("{$platform}_status", ['queued', 'publishing'])
                ->update(["{$platform}_status" => null]);
        }
        DB::table('social_posts')
            ->where('type', 'photo')
            ->whereIn('status', ['queued', 'publishing'])
            ->update(['status' => 'pending_review', 'last_error' => json_encode(self::MIGRATED_ERROR, JSON_UNESCAPED_UNICODE)]);

        // Bài video chưa đăng xong: chọn sẵn nền tảng (worker cũ giữ tài khoản nền tảng nào thì nền tảng đó, không thì TikTok)
        DB::table('social_posts')
            ->where('type', 'video')
            ->whereIn('status', ['generating', 'queued', 'publishing'])
            ->orderBy('id')
            ->each(function ($post) use ($workerPlatform) {
                $platform = $workerPlatform[$post->worker_id] ?? 'tiktok';
                $update = [];
                foreach (['tiktok', 'facebook', 'instagram'] as $p) {
                    $update["{$p}_status"] = $p === $platform ? 'queued' : null;
                }
                // Đang đăng theo job cũ (đã bỏ ở trên): về chờ worker nhận lại
                if ($post->status === 'publishing') {
                    $update['status'] = 'queued';
                }
                DB::table('social_posts')->where('id', $post->id)->update($update);
            });

        // Bài ảnh chưa có giờ đăng: mặc định lúc tạo bài (chỉ để hiển thị; đăng lại sẽ đặt giờ mới)
        DB::table('social_posts')->whereNull('scheduled_at')->update(['scheduled_at' => DB::raw('created_at')]);

        // Nền tảng chủ shop đã chọn (ý định đăng), tách khỏi trạng thái từng nền tảng: lấy từ các cột trạng thái đang có
        Schema::table('social_posts', function (Blueprint $table) {
            $table->json('platforms')->nullable()->after('instagram_status');
            // Worker hỏi bài (ảnh hoặc video) đã tới giờ của nó
            $table->index(['status', 'worker_id', 'scheduled_at']);
        });
        DB::table('social_posts')->orderBy('id')->each(function ($post) {
            $platforms = array_values(array_filter(['tiktok', 'facebook', 'instagram'], fn ($p) => $post->{"{$p}_status"} !== null));
            if ($platforms) {
                DB::table('social_posts')->where('id', $post->id)->update(['platforms' => json_encode($platforms)]);
            }
        });

        // 3. Job gửi TikTok qua API (TikTok tự kết nối): xoá
        DB::table('social_publish_jobs')->where('driver', 'api')->delete();

        // 4. Job: 1 job / bài chứa mọi nền tảng; bỏ cột tài khoản / API / hạn
        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->json('platforms')->nullable()->after('platform');
        });
        DB::table('social_publish_jobs')->whereNotNull('platform')->orderBy('id')->each(function ($job) {
            DB::table('social_publish_jobs')->where('id', $job->id)->update(['platforms' => json_encode([$job->platform])]);
        });

        foreach (Schema::getForeignKeys('social_publish_jobs') as $fk) {
            if ($fk['columns'] === ['publish_account_id']) {
                Schema::table('social_publish_jobs', fn (Blueprint $table) => $table->dropForeign($fk['name']));
            }
        }
        // MySQL tự gỡ cột bị xoá khỏi các index (vd (status, platform, id) còn (status, id))
        Schema::table('social_publish_jobs', function (Blueprint $table) {
            $table->dropColumn(['platform', 'driver', 'publish_account_id', 'account_open_id', 'account_name', 'external_id', 'checked_at', 'lease_expires_at']);
        });

        // 5. Tài khoản đăng bài (worker + TikTok tự kết nối, token): xoá hẳn
        Schema::dropIfExists('publish_accounts');
    }

    public function down(): void
    {
        throw new RuntimeException('Không hoàn tác được: dữ liệu tài khoản đăng bài / TikTok đã xoá.');
    }
};
