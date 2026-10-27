<?php

use App\Http\Controllers\Api\V1\Admin\CreditController as AdminCreditController;
use App\Http\Controllers\Api\V1\AiModelController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Creator\CreativeAssetController;
use App\Http\Controllers\Api\V1\Creator\CreativeController;
use App\Http\Controllers\Api\V1\Creator\CreativeGroupController;
use App\Http\Controllers\Api\V1\Creator\CreativeImageController;
use App\Http\Controllers\Api\V1\Creator\DashboardController;
use App\Http\Controllers\Api\V1\Creator\ProductController;
use App\Http\Controllers\Api\V1\Creator\ProfileController;
use App\Http\Controllers\Api\V1\Creator\StaffController;
use App\Http\Controllers\Api\V1\CreditWalletController;
use App\Http\Controllers\Api\V1\GenerationController;
use App\Http\Controllers\Api\V1\ImageBatchController;
use App\Http\Controllers\Api\V1\MarketplaceController;
use App\Http\Controllers\Api\V1\Shipping\GhnController;
use App\Http\Controllers\Api\V1\Shipping\ShippingOrderController;
use App\Http\Controllers\Api\V1\Social\PostController;
use App\Http\Controllers\Api\V1\Social\TikTokController;
use App\Http\Controllers\Api\V1\Social\TikTokWebhookController;
use App\Http\Controllers\Api\V1\StaffShopController;
use App\Http\Controllers\Api\V1\WorkerContentJobController;
use App\Http\Controllers\Api\V1\WorkerJobController;
use App\Http\Controllers\Api\V1\WorkerPublishJobController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('google', [AuthController::class, 'google'])->middleware('throttle:auth');
        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    // Webhook TikTok (TikTok gọi, xác thực bằng chữ ký): kết quả gửi bài qua API, gỡ quyền ứng dụng
    Route::post('social/tiktok/webhook', TikTokWebhookController::class);

    // Marketplace (công khai)
    Route::get('banners', [MarketplaceController::class, 'banners']);
    Route::get('categories', [MarketplaceController::class, 'categories']);
    Route::get('hashtags', [MarketplaceController::class, 'hashtags']);
    Route::get('creatives', [MarketplaceController::class, 'creatives']);
    Route::get('creatives/{slug}', [MarketplaceController::class, 'creative']);
    Route::get('creators/{username}', [MarketplaceController::class, 'creator']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        // Tạo ảnh
        Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:generations');
        Route::get('generations', [GenerationController::class, 'index']);
        Route::get('generations/{generation}', [GenerationController::class, 'show']);
        Route::get('generations/{generation}/status', [GenerationController::class, 'status']);
        Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->middleware('throttle:generations');
        Route::get('generations/{generation}/outputs/{output}/download', [GenerationController::class, 'download']);

        // Ví Credit
        Route::get('wallet/credit', [CreditWalletController::class, 'show']);
        Route::get('wallet/credit/ledger', [CreditWalletController::class, 'ledger']);

        // Creator Studio
        Route::get('ai-models', [AiModelController::class, 'index']);
        Route::post('creator/profile', [ProfileController::class, 'store']);

        Route::prefix('creator')->middleware('creator')->group(function () {
            Route::get('profile', [ProfileController::class, 'show']);
            Route::put('profile', [ProfileController::class, 'update']);
            Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
            Route::post('profile/cover', [ProfileController::class, 'uploadCover']);

            Route::get('creatives', [CreativeController::class, 'index']);
            Route::post('creatives', [CreativeController::class, 'store']);
            Route::get('creatives/{creative}', [CreativeController::class, 'show']);
            Route::put('creatives/{creative}', [CreativeController::class, 'update']);
            Route::delete('creatives/{creative}', [CreativeController::class, 'destroy']);
            Route::post('creatives/{creative}/publish', [CreativeController::class, 'publish']);
            Route::post('creatives/{creative}/pause', [CreativeController::class, 'pause']);
            Route::post('creatives/{creative}/test', [CreativeController::class, 'test'])->middleware('throttle:generations');

            // Nguyên liệu ẩn (trường hidden_image): tải ảnh trước, lưu Creative thì gắn vào
            Route::get('template-limits', [CreativeAssetController::class, 'limits']);
            Route::post('creative-assets', [CreativeAssetController::class, 'store']);

            Route::post('creatives/{creative}/images', [CreativeImageController::class, 'store']);
            Route::patch('creatives/{creative}/images/order', [CreativeImageController::class, 'reorder']);
            Route::delete('creatives/{creative}/images/{image}', [CreativeImageController::class, 'destroy']);

            // Tạo lô tự động: sản phẩm + nhóm creative (note/auto-tao-lo.txt ở repo frontend). Xem: nhóm creator_or_staff bên dưới
            Route::post('products', [ProductController::class, 'store']);
            Route::post('products/{product}', [ProductController::class, 'update'])->whereNumber('product');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');
            Route::post('creative-groups', [CreativeGroupController::class, 'store']);
            Route::put('creative-groups/{group}', [CreativeGroupController::class, 'update'])->whereNumber('group');
            Route::delete('creative-groups/{group}', [CreativeGroupController::class, 'destroy'])->whereNumber('group');

            // Nhân viên của shop (note/chia-se-tai-nguyen.txt ở repo frontend)
            Route::get('staff', [StaffController::class, 'index']);
            Route::post('staff', [StaffController::class, 'store']);
            Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->whereNumber('staff');
            Route::get('staff-batches', [StaffController::class, 'batches']);
            Route::get('staff-batches/{batch}', [StaffController::class, 'batch'])->whereNumber('batch');

            Route::get('dashboard', [DashboardController::class, 'dashboard']);
            Route::get('wallet', [DashboardController::class, 'wallet']);
            Route::get('wallet/transactions', [DashboardController::class, 'walletTransactions']);

            // Tạo đơn GHN từ ảnh chat (note/tao-don-ghn-tu-anh.txt ở repo frontend). AI đọc ảnh gọi thẳng Claude API
            Route::get('shipping/ghn-settings', [GhnController::class, 'settings']);
            Route::put('shipping/ghn-settings', [GhnController::class, 'saveSettings']);
            Route::get('shipping-orders', [ShippingOrderController::class, 'index']);
            Route::post('shipping-orders/parse', [ShippingOrderController::class, 'parse'])->middleware('throttle:generations');
            Route::get('shipping-orders/{order}', [ShippingOrderController::class, 'show'])->whereNumber('order');
            Route::put('shipping-orders/{order}', [ShippingOrderController::class, 'update'])->whereNumber('order');
            Route::delete('shipping-orders/{order}', [ShippingOrderController::class, 'destroy'])->whereNumber('order');
            Route::post('shipping-orders/{order}/submit', [ShippingOrderController::class, 'submit'])->whereNumber('order');
        });

        // Sản phẩm / nhóm creative: Creator xem của mình, nhân viên xem của shop chủ (note/chia-se-tai-nguyen.txt ở repo frontend)
        Route::prefix('creator')->middleware('creator_or_staff')->group(function () {
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product');
            Route::get('creative-groups', [CreativeGroupController::class, 'index']);
        });

        // Nhân viên của shop: rời shop, logo chèn vào ảnh, creative của shop để tạo lô tay
        Route::prefix('staff')->group(function () {
            Route::delete('shop', [StaffShopController::class, 'leave']);
            Route::post('logo', [StaffShopController::class, 'uploadLogo']);
            Route::get('creatives', [StaffShopController::class, 'creatives']);
        });

        // Danh mục địa chỉ GHN (gọi bằng token GHN của shop, có cache)
        Route::prefix('shipping/ghn')->group(function () {
            Route::get('provinces', [GhnController::class, 'provinces']);
            Route::get('districts', [GhnController::class, 'districts']);
            Route::get('wards', [GhnController::class, 'wards']);
        });

        // Tạo ảnh theo lô (note/tach-lo-anh-dang-tiktok.txt)
        Route::prefix('image-batches')->group(function () {
            Route::get('/', [ImageBatchController::class, 'index']);
            Route::post('/', [ImageBatchController::class, 'store'])->middleware('throttle:generations');
            Route::get('auto/preview', [ImageBatchController::class, 'autoPreview']);
            Route::post('auto', [ImageBatchController::class, 'autoStore'])->middleware('throttle:generations');
            Route::get('{batch}', [ImageBatchController::class, 'show'])->whereNumber('batch');
            Route::delete('{batch}', [ImageBatchController::class, 'destroy'])->whereNumber('batch');
            Route::get('{batch}/images/{image}/inputs', [ImageBatchController::class, 'imageInputs'])->whereNumber(['batch', 'image']);
            Route::post('{batch}/images/{image}/regenerate', [ImageBatchController::class, 'regenerate'])
                ->whereNumber(['batch', 'image'])->middleware('throttle:generations');
            Route::post('{batch}/images/{image}/crop', [ImageBatchController::class, 'crop'])->whereNumber(['batch', 'image']);
            Route::delete('{batch}/images/{image}', [ImageBatchController::class, 'destroyImage'])->whereNumber(['batch', 'image']);
        });

        // Đăng bài lên TikTok / Facebook / Instagram: bấm đăng tạo 1 job / nền tảng cho worker (note/dang-bai-da-nen-tang.txt ở repo frontend)
        Route::prefix('social')->group(function () {
            Route::get('accounts', [PostController::class, 'accounts']);
            Route::get('posts', [PostController::class, 'index']);
            Route::post('posts/from-images', [PostController::class, 'storeFromImages']);
            Route::get('posts/{post}', [PostController::class, 'show'])->whereNumber('post');
            Route::delete('posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');
            Route::post('posts/{post}/publish', [PostController::class, 'publish'])->whereNumber('post');

            // Người dùng tự kết nối TikTok để đăng qua API (note/dang-tiktok-qua-api.txt ở repo frontend)
            Route::post('tiktok/oauth/authorize', [TikTokController::class, 'authorizeUrl'])->middleware('throttle:auth');
            Route::post('tiktok/oauth/callback', [TikTokController::class, 'callback'])->middleware('throttle:auth');
            // Một tài khoản A51 kết nối nhiều tài khoản TikTok (note/uy-quyen-nhieu-tiktok.txt ở repo frontend)
            Route::delete('tiktok/accounts/{account}', [TikTokController::class, 'destroy'])->whereNumber('account');
        });

        // Admin
        Route::prefix('admin')->middleware('admin')->group(function () {
            Route::post('users/{user}/credits', [AdminCreditController::class, 'topup']);
        });
    });
});

// Worker tạo ảnh nội bộ (xác thực bằng token worker): chỉ nhận job của các tài khoản admin gắn với worker.
Route::prefix('v1/jobs')->middleware('worker')->group(function () {
    Route::get('/', [WorkerJobController::class, 'next']);
    Route::post('{id}', [WorkerJobController::class, 'submit'])->whereNumber('id');
});

// Worker viết nội dung bài đăng (cùng token worker, tách khỏi job ảnh).
Route::prefix('v1/content-jobs')->middleware('worker')->group(function () {
    Route::get('/', [WorkerContentJobController::class, 'next']);
    Route::post('{id}', [WorkerContentJobController::class, 'submit'])->whereNumber('id');
});

// Worker đăng bài: giữ 1 tài khoản của 1 nền tảng (admin gắn), chỉ nhận job của nền tảng đó.
Route::prefix('v1/publish-jobs')->middleware('worker')->group(function () {
    Route::get('/', [WorkerPublishJobController::class, 'next']);
    Route::post('{id}', [WorkerPublishJobController::class, 'submit'])->whereNumber('id');
});
