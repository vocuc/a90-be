<?php

namespace App\Console\Commands;

use App\Enums\TikTokAccountStatus;
use App\Models\TikTokAccount;
use App\Services\TikTok\TikTokTokenManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Làm mới token các tài khoản có refresh token sắp hết hạn (< 30 ngày) để người dùng không phải ủy quyền lại;
 * đánh dấu expired các tài khoản đã quá hạn refresh token.
 */
class RefreshTikTokTokens extends Command
{
    protected $signature = 'tiktok:refresh-tokens';

    protected $description = 'Làm mới token TikTok sắp hết hạn';

    public function handle(TikTokTokenManager $tokens): int
    {
        $expired = TikTokAccount::query()
            ->where('status', TikTokAccountStatus::Active)
            ->where('refresh_token_expires_at', '<', now())
            ->update(['status' => TikTokAccountStatus::Expired]);

        $refreshed = 0;
        $failed = 0;
        TikTokAccount::query()
            ->where('status', TikTokAccountStatus::Active)
            ->whereNotNull('refresh_token')
            ->where('refresh_token_expires_at', '<', now()->addDays(30))
            ->each(function (TikTokAccount $account) use ($tokens, &$refreshed, &$failed) {
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
