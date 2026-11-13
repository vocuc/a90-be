<?php

use App\Http\Controllers\Api\V1\AiModelController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Creator\AiContentSettingController;
use App\Http\Controllers\Api\V1\Creator\CreativeAssetController;
use App\Http\Controllers\Api\V1\Creator\CreativeController;
use App\Http\Controllers\Api\V1\Creator\CreativeGroupController;
use App\Http\Controllers\Api\V1\Creator\CreativeImageController;
use App\Http\Controllers\Api\V1\Creator\ProductController;
use App\Http\Controllers\Api\V1\Creator\ProfileController;
use App\Http\Controllers\Api\V1\Creator\ShopWorkerController;
use App\Http\Controllers\Api\V1\Creator\VideoTemplateController;
use App\Http\Controllers\Api\V1\GenerationController;
use App\Http\Controllers\Api\V1\ImageBatchController;
use App\Http\Controllers\Api\V1\ImageWorkerController;
use App\Http\Controllers\Api\V1\MarketplaceController;
use App\Http\Controllers\Api\V1\Shipping\GhnController;
use App\Http\Controllers\Api\V1\Shipping\ShippingOrderController;
use App\Http\Controllers\Api\V1\Social\PostController;
use App\Http\Controllers\Api\V1\Social\VideoPostController;
use App\Http\Controllers\Api\V1\WorkerJobController;
use App\Http\Controllers\Api\V1\WorkerPostJobController;
use App\Http\Controllers\Api\V1\WorkerVideoImageController;
use App\Http\Controllers\Api\V1\WorkerVideoTemplateController;
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
        Route::get('generations/filters', [GenerationController::class, 'filters']);
        Route::get('generations/{generation}', [GenerationController::class, 'show']);
        Route::get('generations/{generation}/status', [GenerationController::class, 'status']);
        Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->middleware('throttle:generations');
        Route::get('generations/{generation}/outputs/{output}/download', [GenerationController::class, 'download']);
        Route::post('generations/{generation}/outputs/{output}/disable', [GenerationController::class, 'disableOutput']);
        Route::post('generations/{generation}/outputs/{output}/enable', [GenerationController::class, 'enableOutput']);
        // Worker tạo ảnh được gắn cho tài khoản, chọn khi tạo ảnh / tạo lô (note/chon-worker-tao-anh.txt ở repo frontend)
        Route::get('image-workers', [ImageWorkerController::class, 'index']);

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

            // Tạo lô tự động: sản phẩm + nhóm creative (note/auto-tao-lo.txt ở repo frontend)
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product');
            Route::post('products', [ProductController::class, 'store']);
            Route::post('products/{product}', [ProductController::class, 'update'])->whereNumber('product');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');
            Route::put('products/{product}/active', [ProductController::class, 'setActive'])->whereNumber('product');
            Route::post('products/shares', [ProductController::class, 'bulkShare']);
            Route::get('products/{product}/shares', [ProductController::class, 'shares'])->whereNumber('product');
            Route::put('products/{product}/shares', [ProductController::class, 'updateShares'])->whereNumber('product');
            Route::get('products/{product}/images/{image}/file', [ProductController::class, 'imageFile'])->whereNumber(['product', 'image']);
            Route::get('creative-groups', [CreativeGroupController::class, 'index']);
            Route::post('creative-groups', [CreativeGroupController::class, 'store']);
            Route::put('creative-groups/{group}', [CreativeGroupController::class, 'update'])->whereNumber('group');
            Route::delete('creative-groups/{group}', [CreativeGroupController::class, 'destroy'])->whereNumber('group');

            // Trang "AI viết nội dung": kho hashtag, bài viết mẫu, số ký tự tối đa (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend)
            Route::get('ai-content', [AiContentSettingController::class, 'show']);
            Route::put('ai-content', [AiContentSettingController::class, 'update']);

            // Quản lý video template (note/quan-ly-video-template.txt ở repo frontend)
            Route::get('video-templates', [VideoTemplateController::class, 'index']);
            Route::post('video-templates', [VideoTemplateController::class, 'store']);
            Route::get('video-templates/{template}', [VideoTemplateController::class, 'show'])->whereNumber('template');
            Route::put('video-templates/{template}', [VideoTemplateController::class, 'update'])->whereNumber('template');
            Route::patch('video-templates/{template}/enabled', [VideoTemplateController::class, 'setEnabled'])->whereNumber('template');
            Route::delete('video-templates/{template}', [VideoTemplateController::class, 'destroy'])->whereNumber('template');

            // Worker admin gắn cho shop: xem, đổi tên, tạo lại token (note/quan-ly-worker.txt ở repo frontend)
            Route::get('workers', [ShopWorkerController::class, 'index']);
            Route::patch('workers/{worker}/name', [ShopWorkerController::class, 'rename'])->whereNumber('worker');
            Route::post('workers/{worker}/token', [ShopWorkerController::class, 'regenerateToken'])->whereNumber('worker');

            // Tạo đơn GHN từ ảnh chat (note/tao-don-ghn-tu-anh.txt ở repo frontend). AI đọc ảnh gọi thẳng Claude API
            Route::get('shipping/ghn-settings', [GhnController::class, 'settings']);
            Route::put('shipping/ghn-settings', [GhnController::class, 'saveSettings']);
            Route::get('shipping-orders', [ShippingOrderController::class, 'index']);
            Route::post('shipping-orders/parse', [ShippingOrderController::class, 'parse'])->middleware('throttle:generations');
            Route::post('shipping-orders/sync-statuses', [ShippingOrderController::class, 'syncStatuses'])->middleware('throttle:10,1');
            Route::get('shipping-orders/{order}', [ShippingOrderController::class, 'show'])->whereNumber('order');
            Route::put('shipping-orders/{order}', [ShippingOrderController::class, 'update'])->whereNumber('order');
            Route::delete('shipping-orders/{order}', [ShippingOrderController::class, 'destroy'])->whereNumber('order');
            Route::post('shipping-orders/{order}/submit', [ShippingOrderController::class, 'submit'])->whereNumber('order');
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
            Route::post('{batch}/cancel', [ImageBatchController::class, 'cancel'])->whereNumber('batch');
            Route::get('{batch}/images/{image}/inputs', [ImageBatchController::class, 'imageInputs'])->whereNumber(['batch', 'image']);
            Route::post('{batch}/images/{image}/regenerate', [ImageBatchController::class, 'regenerate'])
                ->whereNumber(['batch', 'image'])->middleware('throttle:generations');
            Route::post('{batch}/images/{image}/crop', [ImageBatchController::class, 'crop'])->whereNumber(['batch', 'image']);
            Route::delete('{batch}/images/{image}', [ImageBatchController::class, 'destroyImage'])->whereNumber(['batch', 'image']);
        });

        // Bài đăng ảnh / video: bấm đăng giao cho worker đã chọn, worker đăng lên các nền tảng khi tới giờ
        // (note/gop-luong-dang-bai.txt ở repo frontend)
        Route::prefix('social')->group(function () {
            Route::get('posts', [PostController::class, 'index']);
            Route::post('posts/from-images', [PostController::class, 'storeFromImages']);
            Route::get('posts/{post}', [PostController::class, 'show'])->whereNumber('post');
            Route::delete('posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');
            Route::post('posts/{post}/publish', [PostController::class, 'publish'])->whereNumber('post');
            Route::post('posts/{post}/retry', [PostController::class, 'retry'])->whereNumber('post');
            // "Viết lại bằng AI" (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend): trả nội dung, không lưu vào bài
            Route::post('posts/{post}/ai-content', [PostController::class, 'aiContent'])->whereNumber('post')->middleware('throttle:generations');

            // Bài đăng video có lịch: worker dựng video + tự đăng khi tới giờ (note/lap-lich-worker.txt ở repo frontend)
            Route::prefix('video-posts')->middleware('creator')->group(function () {
                Route::post('auto', [VideoPostController::class, 'auto'])->middleware('throttle:generations');
                Route::post('/', [VideoPostController::class, 'store'])->middleware('throttle:generations');
                Route::get('images', [VideoPostController::class, 'images']);
                Route::get('templates', [VideoPostController::class, 'templates']);
                Route::put('{post}', [VideoPostController::class, 'update'])->whereNumber('post');
                Route::post('{post}/retry', [VideoPostController::class, 'retry'])->whereNumber('post');
            });

        });
    });
});

// Worker tạo ảnh nội bộ (xác thực bằng token worker): chỉ nhận job của các tài khoản admin gắn với worker.
Route::prefix('v1/jobs')->middleware('worker')->group(function () {
    Route::get('/', [WorkerJobController::class, 'next']);
    Route::post('{id}', [WorkerJobController::class, 'submit'])->whereNumber('id');
});

// Worker đăng bài (ảnh / video): nhận bài đã tới giờ được giao cho chính worker, 1 job / bài chứa mọi nền tảng
// (note/gop-luong-dang-bai.txt ở repo frontend)
Route::prefix('v1/post-jobs')->middleware('worker')->group(function () {
    Route::get('/', [WorkerPostJobController::class, 'next']);
    Route::post('{id}', [WorkerPostJobController::class, 'submit'])->whereNumber('id');
});

// Worker tạo video: gắn 1 tài khoản A51 (admin gắn), xin N ảnh ngẫu nhiên trong các lô ảnh của shop đó để dựng video.
Route::get('v1/video-images', [WorkerVideoImageController::class, 'index'])->middleware('worker');

// Máy làm template (D:ideo-tmp, npm run sync-templates) đồng bộ danh sách template vào shop worker được gắn (tài khoản tạo video)
Route::put('v1/video-templates/sync', [WorkerVideoTemplateController::class, 'sync'])->middleware('worker');
