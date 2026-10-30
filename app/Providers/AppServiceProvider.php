<?php

namespace App\Providers;

use App\Services\Shipping\ClaudeShippingOrderReader;
use App\Services\Shipping\ShippingOrderReader;
use App\Services\Social\ClaudeSocialContentWriter;
use App\Services\Social\SocialContentWriter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tạo đơn GHN từ ảnh: AI đọc ảnh gọi thẳng Claude API (test thay bằng bản giả)
        $this->app->bind(ShippingOrderReader::class, ClaudeShippingOrderReader::class);
        // AI viết nội dung bài đăng: gọi thẳng Claude API (test thay bằng bản giả)
        $this->app->bind(SocialContentWriter::class, ClaudeSocialContentWriter::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Trang quản trị dùng AdminLTE 3 (Bootstrap 4).
        Paginator::useBootstrapFour();
        Blade::directive('credit', fn (string $expression) => "<?php echo number_format((int) ({$expression}), 0, ',', '.'); ?>");

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('generations', fn (Request $request) => Limit::perMinute(config('ai90.generation.rate_limit_per_minute'))
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
