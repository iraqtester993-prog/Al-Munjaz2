<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [DashboardController::class, 'show']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/chats', [ChatController::class, 'index']);
        Route::get('/chats/{chat}', [ChatController::class, 'show']);
        Route::post('/chats', [ChatController::class, 'store']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::patch('/admin/users/{user}', [AdminController::class, 'updateUser']);
        Route::get('/admin/couriers', [AdminController::class, 'couriers']);
        Route::patch('/admin/orders/{order}/courier', [AdminController::class, 'assignCourier']);
    });
});
