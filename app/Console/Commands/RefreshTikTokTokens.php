<?php

namespace App\Console\Commands;

use App\Enums\PublishDriver;
use App\Enums\TikTokConnectionStatus;
use App\Models\PublishAccount;
use App\Services\TikTok\TikTokTokenManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Làm mới token TikTok (tài khoản người dùng tự kết nối) có refresh token sắp hết hạn (< 30 ngày) để người dùng không phải
 * kết nối lại; đánh dấu expired các tài khoản đã quá hạn refresh token.
 */
class RefreshTikTokTokens extends Command
{
    protected $signature = 'tiktok:refresh-tokens';

    protected $description = 'Làm mới token TikTok sắp hết hạn';

    public function handle(TikTokTokenManager $tokens): int
    {
        $connections = fn () => PublishAccount::query()
            ->where('driver', PublishDriver::Api)
            ->where('connection_status', TikTokConnectionStatus::Active);

        $expired = $connections()
            ->where('refresh_token_expires_at', '<', now())
            ->update(['connection_status' => TikTokConnectionStatus::Expired]);

        $refreshed = 0;
        $failed = 0;
        $connections()
            ->whereNotNull('refresh_token')
            ->where('refresh_token_expires_at', '<', now()->addDays(30))
            ->each(function (PublishAccount $account) use ($tokens, &$refreshed, &$failed) {
                try {
                    $tokens->refresh($account, force: true);
                    $refreshed++;
                } catch (Throwable) {
                    $failed++; // lỗi token -> đã chuyển expired; lỗi mạng -> thử lại lần chạy sau
                }
            });

        $this->info("Làm mới {$refreshed} tài khoản, lỗi {$failed}, hết hạn {$expired}.");

        return self::SUCCESS;
    }
}
