<?php

use App\Http\Controllers\Api\V1\AiModelController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChannelPlanApiController;
use App\Http\Controllers\Api\V1\Creator\ChannelContentController;
use App\Http\Controllers\Api\V1\Creator\ChannelController;
use App\Http\Controllers\Api\V1\Creator\CreativeAssetController;
use App\Http\Controllers\Api\V1\Creator\CreativeController;
use App\Http\Controllers\Api\V1\Creator\CreativeGroupController;
use App\Http\Controllers\Api\V1\Creator\CreativeImageController;
use App\Http\Controllers\Api\V1\Creator\ProductController;
use App\Http\Controllers\Api\V1\Creator\ProfileController;
use App\Http\Controllers\Api\V1\Creator\PromptTemplateController;
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
use App\Http\Controllers\Api\V1\WorkerVideoImageController;
use App\Http\Controllers\Api\V1\WorkerVideoTemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('google', [AuthController::class, 'google'])->middleware('throttle:auth');
        Route::middleware(['auth:sanctum', 'active', 'full-token'])->group(function () {
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
    // Hashtag loại giày dùng chung A51 + OOTD (skill /tao-outfit, /gan-tag-san-pham)
    Route::get('shoe-types', [\App\Http\Controllers\Api\V1\ShoeTypeController::class, 'index']);

    Route::middleware(['auth:sanctum', 'active', 'full-token'])->group(function () {

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

            // Template prompt: các block ghép thành prompt, Creative chọn Template thì prompt = Template + prompt riêng
            Route::get('prompt-templates', [PromptTemplateController::class, 'index']);
            Route::post('prompt-templates', [PromptTemplateController::class, 'store']);
            Route::get('prompt-templates/{template}', [PromptTemplateController::class, 'show'])->whereNumber('template');
            Route::put('prompt-templates/{template}', [PromptTemplateController::class, 'update'])->whereNumber('template');
            Route::delete('prompt-templates/{template}', [PromptTemplateController::class, 'destroy'])->whereNumber('template');

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
            // Chủ shop sửa tag sản phẩm (AI gắn bằng /gan-tag-san-pham; trường chủ shop sửa AI không ghi đè)
            Route::put('products/{product}/tags', [ProductController::class, 'updateTags'])->whereNumber('product');
            // Gắn tag bằng AI: chọn sản phẩm -> việc cho worker gắn tag (a90-fe note/xay-kenh-tiktok/worker-gan-tag.txt)
            Route::get('products/tag-jobs/latest', [ProductController::class, 'latestTagJob']);
            Route::post('products/tag-jobs', [ProductController::class, 'startTagJob']);
            Route::post('products/shares', [ProductController::class, 'bulkShare']);
            Route::get('products/{product}/shares', [ProductController::class, 'shares'])->whereNumber('product');
            Route::put('products/{product}/shares', [ProductController::class, 'updateShares'])->whereNumber('product');
            Route::get('products/{product}/images/{image}/file', [ProductController::class, 'imageFile'])->whereNumber(['product', 'image']);
            Route::get('creative-groups', [CreativeGroupController::class, 'index']);
            Route::post('creative-groups', [CreativeGroupController::class, 'store']);
            Route::put('creative-groups/{group}', [CreativeGroupController::class, 'update'])->whereNumber('group');
            Route::delete('creative-groups/{group}', [CreativeGroupController::class, 'destroy'])->whereNumber('group');

            // Kênh (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt): thay trang "AI viết nội dung" theo shop cũ
            Route::get('channels', [ChannelController::class, 'index']);
            Route::post('channels', [ChannelController::class, 'store']);
            Route::get('channels/post-samples', [ChannelController::class, 'postSamples']);
            Route::get('channels/{channel}', [ChannelController::class, 'show'])->whereNumber('channel');
            Route::put('channels/{channel}', [ChannelController::class, 'update'])->whereNumber('channel');
            Route::delete('channels/{channel}', [ChannelController::class, 'destroy'])->whereNumber('channel');
            Route::get('channel-workers', [ChannelController::class, 'workers']);
            // Nội dung kênh: chủ shop xem / duyệt (lệnh /lap-ke-hoach soạn qua /api/v1/channel-plan)
            Route::get('channels/{channel}/lineups', [ChannelContentController::class, 'lineups'])->whereNumber('channel');
            Route::put('channels/{channel}/lineups/{lineup}', [ChannelContentController::class, 'updateLineup'])->whereNumber(['channel', 'lineup']);
            Route::post('channels/{channel}/lineups/{lineup}/{decision}', [ChannelContentController::class, 'decideLineup'])
                ->whereNumber(['channel', 'lineup'])->whereIn('decision', ['approve', 'reject']);
            Route::get('channels/{channel}/text-templates', [ChannelContentController::class, 'textTemplates'])->whereNumber('channel');
            Route::post('channels/{channel}/text-templates', [ChannelContentController::class, 'storeTextTemplate'])->whereNumber('channel');
            Route::put('channels/{channel}/text-templates/{template}', [ChannelContentController::class, 'updateTextTemplate'])->whereNumber(['channel', 'template']);
            Route::post('channels/{channel}/text-templates/{template}/{decision}', [ChannelContentController::class, 'decideTextTemplate'])
                ->whereNumber(['channel', 'template'])->whereIn('decision', ['approve', 'reject']);
            Route::get('channels/{channel}/plans', [ChannelContentController::class, 'plans'])->whereNumber('channel');
            Route::get('channels/{channel}/plan-job', [ChannelContentController::class, 'planJob'])->whereNumber('channel');
            Route::post('channels/{channel}/plan-jobs', [ChannelContentController::class, 'startPlanJob'])->whereNumber('channel');
            Route::get('channels/{channel}/plans/{plan}', [ChannelContentController::class, 'plan'])->whereNumber(['channel', 'plan']);
            Route::delete('channels/{channel}/plans/{plan}', [ChannelContentController::class, 'deletePlan'])->whereNumber(['channel', 'plan']);
            Route::post('channels/{channel}/plans/{plan}/reset-times', [ChannelContentController::class, 'resetPlanTimes'])->whereNumber(['channel', 'plan']);
            Route::post('channels/{channel}/plans/{plan}/reschedule', [ChannelContentController::class, 'reschedulePlan'])->whereNumber(['channel', 'plan']);
            Route::post('channels/{channel}/plans/{plan}/redo', [ChannelContentController::class, 'redoPlan'])->whereNumber(['channel', 'plan']);
            Route::delete('channels/{channel}/plans/{plan}/redo', [ChannelContentController::class, 'redoPlan'])->whereNumber(['channel', 'plan']);
            Route::post('channels/{channel}/plans/{plan}/{decision}', [ChannelContentController::class, 'decidePlan'])
                ->whereNumber(['channel', 'plan'])->whereIn('decision', ['approve', 'cancel']);
            Route::put('channels/{channel}/plans/{plan}/items/{item}', [ChannelContentController::class, 'updateItem'])->whereNumber(['channel', 'plan', 'item']);
            Route::post('channels/{channel}/plans/{plan}/items/{item}/cancel', [ChannelContentController::class, 'cancelItem'])->whereNumber(['channel', 'plan', 'item']);
            Route::post('channels/{channel}/plans/{plan}/items/{item}/redo', [ChannelContentController::class, 'redoItem'])->whereNumber(['channel', 'plan', 'item']);
            Route::delete('channels/{channel}/plans/{plan}/items/{item}/redo', [ChannelContentController::class, 'redoItem'])->whereNumber(['channel', 'plan', 'item']);
            Route::get('channels/{channel}/calendar', [ChannelContentController::class, 'calendar'])->whereNumber('channel');
            // Chủ đề kênh (AI tự tạo khi lập kế hoạch; chủ shop sửa tên / tags, tạm tắt)
            Route::get('channels/{channel}/topics', [ChannelContentController::class, 'topics'])->whereNumber('channel');
            Route::post('channels/{channel}/topics', [ChannelContentController::class, 'storeTopic'])->whereNumber('channel');
            // Xây dựng chủ đề tự động: tạo việc cho worker lập chủ đề (a90-fe note/xay-kenh-tiktok/worker-lap-chu-de.txt)
            Route::post('channels/{channel}/topic-jobs', [ChannelContentController::class, 'startTopicJob'])->whereNumber('channel');
            Route::put('channels/{channel}/topics/{topic}', [ChannelContentController::class, 'updateTopic'])->whereNumber(['channel', 'topic']);
            // Xoá chủ đề (kể cả đã có bài): bài đăng / bài kế hoạch của chủ đề thành "không xác định" (topic_id null)
            Route::delete('channels/{channel}/topics/{topic}', [ChannelContentController::class, 'destroyTopic'])->whereNumber(['channel', 'topic']);
            // Nhóm chủ đề do chủ shop quản lý (2026-11-24)
            Route::post('channels/{channel}/topic-categories', [ChannelContentController::class, 'storeTopicCategory'])->whereNumber('channel');
            Route::put('channels/{channel}/topic-categories/order', [ChannelContentController::class, 'orderTopicCategories'])->whereNumber('channel');
            Route::put('channels/{channel}/topic-categories/{category}', [ChannelContentController::class, 'updateTopicCategory'])->whereNumber(['channel', 'category']);
            Route::delete('channels/{channel}/topic-categories/{category}', [ChannelContentController::class, 'destroyTopicCategory'])->whereNumber(['channel', 'category']);
            Route::get('channel-posts/{post}', [ChannelContentController::class, 'channelPost'])->whereNumber('post');
            Route::put('channel-posts/{post}/overlays', [ChannelContentController::class, 'updateOverlays'])->whereNumber('post');
            Route::post('channel-posts/{post}/redo', [ChannelContentController::class, 'redo'])->whereNumber('post');
            Route::delete('channel-posts/{post}/redo', [ChannelContentController::class, 'redo'])->whereNumber('post');

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
            // Xoá nhiều lô / tất cả lô (ảnh vẫn giữ trong lịch sử tạo ảnh)
            Route::post('bulk-delete', [ImageBatchController::class, 'bulkDestroy']);
            Route::post('auto', [ImageBatchController::class, 'autoStore'])->middleware('throttle:generations');
            Route::get('{batch}', [ImageBatchController::class, 'show'])->whereNumber('batch');
            Route::delete('{batch}', [ImageBatchController::class, 'destroy'])->whereNumber('batch');
            Route::post('{batch}/cancel', [ImageBatchController::class, 'cancel'])->whereNumber('batch');
            Route::post('{batch}/retry-failed', [ImageBatchController::class, 'retryFailed'])->whereNumber('batch');
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
            // Lập lịch đăng bài nhiều lô: đưa vào queue, mỗi lô 1 bài, giờ đăng dải đều trong khung giờ
            Route::post('posts/schedule-batches', [PostController::class, 'scheduleBatches'])->middleware('throttle:generations');
            Route::get('posts/{post}', [PostController::class, 'show'])->whereNumber('post');
            Route::delete('posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');
            Route::post('posts/{post}/publish', [PostController::class, 'publish'])->whereNumber('post');
            Route::post('posts/{post}/retry', [PostController::class, 'retry'])->whereNumber('post');
            // "Viết lại bằng AI" (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend): trả nội dung, không lưu vào bài

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

// MỌI worker (xác thực bằng token worker): 1 API chung, việc lưu ở worker_jobs phân biệt bằng type = loại worker
// (image, outfit, content, publish; a90-fe note/gop-viec-worker.txt). Chỉ nhận việc của các tài khoản admin gắn với worker.
Route::prefix('v1/worker-jobs')->middleware('worker')->group(function () {
    Route::get('/', [WorkerJobController::class, 'next']);
    Route::post('{id}', [WorkerJobController::class, 'submit'])->whereNumber('id');
    Route::post('{id}/extend', [WorkerJobController::class, 'extend'])->whereNumber('id');
    Route::get('{id}/batch', [WorkerJobController::class, 'batch'])->whereNumber('id');
});

// Worker tạo video: gắn 1 tài khoản A51 (admin gắn), xin N ảnh ngẫu nhiên trong các lô ảnh của shop đó để dựng video.
Route::get('v1/video-images', [WorkerVideoImageController::class, 'index'])->middleware('worker');

// Máy làm template (D:ideo-tmp, npm run sync-templates) đồng bộ danh sách template vào shop worker được gắn (tài khoản tạo video)
Route::put('v1/video-templates/sync', [WorkerVideoTemplateController::class, 'sync'])->middleware('worker');

// Lệnh /lap-ke-hoach + /gan-tag-san-pham (skill FlowKit): token WORKER loại Điều phối, chỉ đọc / ghi kênh + sản phẩm của
// shop admin đã gắn cho worker (bỏ token admin, 2026-11-25; a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 6.2)
Route::prefix('v1/channel-plan')->middleware(['throttle:api', 'channel-plan'])->group(function () {
    Route::get('channels/{channel}/context', [ChannelPlanApiController::class, 'context'])->whereNumber('channel');
    Route::put('channels/{channel}/persona', [ChannelPlanApiController::class, 'persona'])->whereNumber('channel');
    Route::post('channels/{channel}/lineups', [ChannelPlanApiController::class, 'lineup'])->whereNumber('channel');
    Route::post('channels/{channel}/text-templates', [ChannelPlanApiController::class, 'textTemplates'])->whereNumber('channel');
    Route::post('channels/{channel}/topics', [ChannelPlanApiController::class, 'topics'])->whereNumber('channel');
    Route::post('channels/{channel}/plans/{plan}/items', [ChannelPlanApiController::class, 'submitPlan'])->whereNumber(['channel', 'plan']);
    Route::post('posts/{post}/redo', [ChannelPlanApiController::class, 'redo'])->whereNumber('post');
    Route::post('plan-items/{item}/redo', [ChannelPlanApiController::class, 'redoItem'])->whereNumber('item');
    // Tag sản phẩm AI + sản phẩm lọc theo tag (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt mục 5, 6)
    Route::get('channels/{channel}/products', [ChannelPlanApiController::class, 'products'])->whereNumber('channel');
    Route::get('shops/{user}/products/untagged', [ChannelPlanApiController::class, 'untaggedProducts'])->whereNumber('user');
    Route::put('shops/{user}/products/tags', [ChannelPlanApiController::class, 'saveProductTags'])->whereNumber('user');
});
