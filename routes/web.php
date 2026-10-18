<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CreativeController;
use App\Http\Controllers\Admin\CreatorController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\GenerationController;
use App\Http\Controllers\Admin\PublishAccountController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkerController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Trang quản trị (AdminLTE)
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.submit');

    Route::middleware('admin.session')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::post('users/{user}/credits', [UserController::class, 'adjustCredit'])->name('users.credits');
        Route::post('users/{user}/toggle-ban', [UserController::class, 'toggleBan'])->name('users.toggle-ban');
        Route::post('users/{user}/toggle-admin', [UserController::class, 'toggleAdmin'])->name('users.toggle-admin');

        Route::get('creators', [CreatorController::class, 'index'])->name('creators.index');
        Route::put('creators/{creator}', [CreatorController::class, 'update'])->name('creators.update');

        Route::get('creatives', [CreativeController::class, 'index'])->name('creatives.index');
        Route::get('creatives/{creative}', [CreativeController::class, 'show'])->name('creatives.show');
        Route::post('creatives/{creative}/hide', [CreativeController::class, 'hide'])->name('creatives.hide');
        Route::post('creatives/{creative}/unhide', [CreativeController::class, 'unhide'])->name('creatives.unhide');

        Route::resource('categories', CategoryController::class)->except('show');
        Route::post('categories/{category}/toggle', [CategoryController::class, 'toggle'])->name('categories.toggle');

        Route::resource('banners', BannerController::class)->except('show');
        Route::post('banners/{banner}/toggle', [BannerController::class, 'toggle'])->name('banners.toggle');

        Route::resource('publish-accounts', PublishAccountController::class)->except('show')->parameters(['publish-accounts' => 'publishAccount']);
        Route::post('publish-accounts/{publishAccount}/toggle', [PublishAccountController::class, 'toggle'])->name('publish-accounts.toggle');

        Route::resource('workers', WorkerController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('workers/{worker}/regenerate', [WorkerController::class, 'regenerate'])->name('workers.regenerate');
        Route::post('workers/{worker}/toggle', [WorkerController::class, 'toggle'])->name('workers.toggle');

        Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
        Route::get('generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');

        Route::get('transactions', [FinanceController::class, 'transactions'])->name('transactions.index');
        Route::get('credit-ledger', [FinanceController::class, 'ledger'])->name('ledger.index');
        Route::get('revenues', [FinanceController::class, 'revenues'])->name('revenues.index');

        Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });
});
