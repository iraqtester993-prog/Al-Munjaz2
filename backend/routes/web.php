<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\App\AppOrderController;
use App\Http\Controllers\App\AppProfileController;
use App\Http\Controllers\App\AppWalletController;
use App\Http\Controllers\App\ChatController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\NotificationController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PWA files served dynamically
|--------------------------------------------------------------------------
|
| The hosting proxy assigns a 30-day cache lifetime to physical .js and
| .json files. Serving these two control files through Laravel guarantees
| that every installed client receives the current worker and manifest.
*/
Route::get('/pwa/manifest.json', fn () => response()->file(
    resource_path('pwa/manifest.json'),
    ['Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']
));

Route::get('/pwa/worker.js', fn () => response()->file(
    resource_path('pwa/worker.js'),
    [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Service-Worker-Allowed' => '/',
    ]
));

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
*/
Route::get('/', function (Request $request) {
    return preg_match('/^(?:dashboard|admin)\./', $request->getHost())
        ? redirect('/dashboard/login')
        : redirect('/login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/dashboard/login', [AuthController::class, 'adminLoginForm'])->middleware('dashboard.host')->name('admin.login');
    Route::post('/dashboard/login', [AuthController::class, 'adminLogin'])->middleware('dashboard.host');
    Route::get('/register/{role}', [AuthController::class, 'registerForm'])
        ->whereIn('role', ['merchant', 'courier'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->name('verify.otp');
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->name('resend.otp');
});

/*
|--------------------------------------------------------------------------
| Authenticated app routes (merchant + courier + admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/app', [DashboardController::class, 'app'])->name('app')->middleware('role:merchant,courier');

    Route::get('/app/profile', [AppProfileController::class, 'index'])->name('app.profile')->middleware('role:merchant,courier');
    Route::post('/profile/update', [AppProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/theme', [AppProfileController::class, 'theme'])->name('profile.theme');
    Route::post('/profile/locale', [AppProfileController::class, 'locale'])->name('profile.locale');

    /*
    |--------------------------------------------------------------------------
    | Merchant & courier shared resources
    |--------------------------------------------------------------------------
    */
    Route::prefix('app')->middleware('role:merchant,courier')->group(function () {
        Route::post('duty', [DashboardController::class, 'duty'])->name('app.duty');
        Route::get('orders', [AppOrderController::class, 'index'])->name('app.orders');
        Route::post('orders', [AppOrderController::class, 'store'])->name('app.orders.store');
        Route::post('orders/{order}/update', [AppOrderController::class, 'update'])->name('app.orders.update');
        Route::post('orders/{order}/status', [AppOrderController::class, 'status'])->name('app.orders.status');
        Route::get('wallet', [AppWalletController::class, 'index'])->name('app.wallet');
        Route::post('wallet/withdraw', [AppWalletController::class, 'withdraw'])->name('app.wallet.withdraw');
        Route::post('wallet/budget', [AppWalletController::class, 'budget'])->name('app.wallet.budget');
        Route::get('chats', [ChatController::class, 'index'])->name('app.chats');
        Route::get('chats/{chat}', [ChatController::class, 'show'])->name('app.chats.show');
        Route::post('chats/{chat}/send', [ChatController::class, 'send'])->name('app.chats.send');
        Route::post('chats/open', [ChatController::class, 'open'])->name('app.chats.open');
        Route::get('notifications', [NotificationController::class, 'index'])->name('app.notifications');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('app.notifications.read-all');
    });
});

/*
|--------------------------------------------------------------------------
| Admin dashboard
|--------------------------------------------------------------------------
*/
Route::prefix('dashboard')->middleware(['dashboard.host', 'auth', 'active', 'role:admin'])->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('orders', [AdminOrderController::class, 'index'])->name('admin.orders');
    Route::get('branches', [BranchController::class, 'index'])->name('admin.branches');
    Route::post('branches', [BranchController::class, 'store'])->name('admin.branches.store');
    Route::post('orders/{order}/status', [AdminOrderController::class, 'status'])->name('admin.orders.status');
    Route::post('orders/{order}/courier', [AdminOrderController::class, 'assignCourier'])->name('admin.orders.courier');
    Route::get('merchants', [AdminUserController::class, 'merchants'])->name('admin.merchants');
    Route::get('couriers', [AdminUserController::class, 'couriers'])->name('admin.couriers');
    Route::post('users/{user}/status', [AdminUserController::class, 'status'])->name('admin.users.status');
    Route::get('users/{user}/documents/{document}', [AdminUserController::class, 'showDocument'])->name('admin.users.documents.show');
    Route::post('users/{user}/documents/{document}/review', [AdminUserController::class, 'reviewDocument'])->name('admin.users.documents.review');
    Route::get('finance', [AdminDashboardController::class, 'finance'])->name('admin.finance');
    Route::get('notifications', [AdminDashboardController::class, 'notifications'])->name('admin.notifications');
    Route::get('chat', [ChatController::class, 'adminIndex'])->name('admin.chat');
    Route::get('chat/{chat}', [ChatController::class, 'adminShow'])->name('admin.chat.show');
    Route::post('chat/{chat}/send', [ChatController::class, 'adminSend'])->name('admin.chat.send');
});

Route::get('/admin', fn () => redirect()->route('admin.dashboard'))->middleware(['dashboard.host', 'auth', 'active', 'role:admin']);
