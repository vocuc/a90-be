<?php

use App\Http\Controllers\Api\V1\Admin\CreditController as AdminCreditController;
use App\Http\Controllers\Api\V1\AiModelController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Creator\CreativeController;
use App\Http\Controllers\Api\V1\Creator\CreativeImageController;
use App\Http\Controllers\Api\V1\Creator\DashboardController;
use App\Http\Controllers\Api\V1\Creator\ProfileController;
use App\Http\Controllers\Api\V1\CreditWalletController;
use App\Http\Controllers\Api\V1\GenerationController;
use App\Http\Controllers\Api\V1\MarketplaceController;
use App\Http\Controllers\Api\V1\WorkerJobController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    // Marketplace (công khai)
    Route::get('banners', [MarketplaceController::class, 'banners']);
    Route::get('categories', [MarketplaceController::class, 'categories']);
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

            Route::post('creatives/{creative}/images', [CreativeImageController::class, 'store']);
            Route::patch('creatives/{creative}/images/order', [CreativeImageController::class, 'reorder']);
            Route::delete('creatives/{creative}/images/{image}', [CreativeImageController::class, 'destroy']);

            Route::get('dashboard', [DashboardController::class, 'dashboard']);
            Route::get('wallet', [DashboardController::class, 'wallet']);
            Route::get('wallet/transactions', [DashboardController::class, 'walletTransactions']);
        });

        // Admin
        Route::prefix('admin')->middleware('admin')->group(function () {
            Route::post('users/{user}/credits', [AdminCreditController::class, 'topup']);
        });
    });
});

// Worker tạo ảnh nội bộ (xác thực bằng token worker, không giới hạn theo user).
Route::prefix('v1/jobs')->middleware('worker')->group(function () {
    Route::get('/', [WorkerJobController::class, 'next']);
    Route::post('{id}', [WorkerJobController::class, 'submit'])->whereNumber('id');
});
