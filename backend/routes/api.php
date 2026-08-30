<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProvinceController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Admin\AdminCourierLocationController;
use App\Http\Controllers\App\CourierLocationController;
use App\Http\Middleware\EnsureMobileApiUser;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/provinces', [ProvinceController::class, 'index']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', EnsureMobileApiUser::class])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [DashboardController::class, 'show']);
        Route::post('/courier/location', [CourierLocationController::class, 'store'])->middleware('throttle:120,1');
        Route::delete('/courier/location', [CourierLocationController::class, 'destroy']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/chats', [ChatController::class, 'index']);
        Route::get('/chats/{chat}', [ChatController::class, 'show']);
        Route::get('/chats/{chat}/messages', [ChatController::class, 'messages']);
        Route::post('/chats', [ChatController::class, 'store']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

        // The order API needs a tenant boundary for merchant writes. Courier
        // reads are intentionally resolved without the scope after an
        // assignment check in OrderController; other API surfaces retain
        // their existing cross-tenant policies.
        Route::middleware(SetTenantContext::class)->group(function (): void {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
            Route::post('/orders/{order}/return', [OrderController::class, 'startReturn']);
            Route::post('/orders/{order}/return-to-merchant', [OrderController::class, 'confirmReturnToMerchant']);
        });
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::patch('/admin/users/{user}', [AdminController::class, 'updateUser']);
        Route::get('/admin/couriers', [AdminController::class, 'couriers']);
        Route::get('/admin/couriers/locations', [AdminCourierLocationController::class, 'index']);
        Route::patch('/admin/orders/{order}/courier', [AdminController::class, 'assignCourier']);
        Route::match(['get', 'put'], '/admin/settings', [AdminController::class, 'settings']);
        Route::get('/admin/reports/finance', [ReportController::class, 'finance']);
    });
});
