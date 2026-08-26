<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFinanceController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminPreferencesController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\App\AppOrderController;
use App\Http\Controllers\App\AppProfileController;
use App\Http\Controllers\App\AppReportController;
use App\Http\Controllers\App\AppWalletController;
use App\Http\Controllers\App\ChatController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\NotificationController;
use App\Http\Controllers\App\PushSubscriptionController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\LocaleController;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
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
$pwaManifest = fn () => response()->file(
    resource_path('pwa/manifest.json'),
    ['Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']
);

$pwaOffline = fn () => response()->file(
    resource_path('pwa/offline.html'),
    ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']
);

$pwaWorker = function () {
    // The Blade page and this dynamic worker obtain their version from the
    // same config value. This prevents an installed PWA from receiving a
    // page that points at one release while the worker advertises another.
    $worker = str_replace(
        '__PWA_VERSION__',
        (string) config('app.pwa_version'),
        file_get_contents(resource_path('pwa/worker.js')),
    );

    return response($worker, 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Service-Worker-Allowed' => '/',
    ]);
};

Route::get('/pwa/manifest', $pwaManifest);
Route::get('/manifest.json', $pwaManifest); // Compatibility alias for the old installed PWA.
Route::get('/pwa/offline', $pwaOffline);
Route::get('/pwa/worker', $pwaWorker);
Route::get('/sw.js', $pwaWorker); // Compatibility alias for the old installed PWA.

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

/*
 * A courier operates on orders owned by a merchant tenant.  Binding an order
 * through the default tenant scope would turn an authorised courier request
 * into a false 404 before the controller can run its access check.
 */
Route::bind('order', fn (string $value) => Order::withoutGlobalScope(TenantScope::class)->findOrFail($value));

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.set');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/dashboard/login', [AuthController::class, 'adminLoginForm'])->middleware('dashboard.host')->name('admin.login');
    Route::post('/dashboard/login', [AuthController::class, 'adminLogin'])->middleware('dashboard.host');
    Route::get('/register/{role}', [AuthController::class, 'registerForm'])
        ->whereIn('role', ['merchant', 'courier'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/verify-otp', [AuthController::class, 'otpForm'])->name('verify.otp.form');
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
    Route::post('/profile/verification', [AppProfileController::class, 'verification'])->name('profile.verification')->middleware('role:merchant');

    /*
    |--------------------------------------------------------------------------
    | Merchant & courier shared resources
    |--------------------------------------------------------------------------
    */
    Route::prefix('app')->middleware('role:merchant,courier')->group(function () {
        Route::post('duty', [DashboardController::class, 'duty'])->name('app.duty');
        Route::get('orders', [AppOrderController::class, 'index'])->name('app.orders');
        Route::get('reports', [AppReportController::class, 'index'])->name('app.reports')->middleware('role:merchant');
        Route::post('orders', [AppOrderController::class, 'store'])->name('app.orders.store');
        Route::post('orders/{order}/update', [AppOrderController::class, 'update'])->name('app.orders.update');
        Route::post('orders/{order}/status', [AppOrderController::class, 'status'])->name('app.orders.status');
        Route::post('orders/{order}/claim', [AppOrderController::class, 'claim'])->name('app.orders.claim');
        Route::get('wallet', [AppWalletController::class, 'index'])->name('app.wallet');
        Route::post('wallet/withdraw', [AppWalletController::class, 'withdraw'])->name('app.wallet.withdraw');
        Route::post('wallet/handover', [AppWalletController::class, 'handover'])->name('app.wallet.handover');
        Route::post('wallet/recharge', [AppWalletController::class, 'recharge'])->name('app.wallet.recharge');
        Route::post('wallet/budget', [AppWalletController::class, 'budget'])->name('app.wallet.budget');
        Route::get('chats', [ChatController::class, 'index'])->name('app.chats');
        Route::get('chats/{chat}', [ChatController::class, 'show'])->name('app.chats.show');
        Route::get('chats/{chat}/messages', [ChatController::class, 'messages'])->name('app.chats.messages');
        Route::post('chats/{chat}/send', [ChatController::class, 'send'])->name('app.chats.send');
        Route::post('chats/open', [ChatController::class, 'open'])->name('app.chats.open');
        Route::get('notifications', [NotificationController::class, 'index'])->name('app.notifications');
        Route::get('notifications/feed', [NotificationController::class, 'feed'])->name('app.notifications.feed');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('app.notifications.read-all');
        Route::get('push/config', [PushSubscriptionController::class, 'config'])->name('app.push.config');
        Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->name('app.push.subscribe');
        Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('app.push.unsubscribe');
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
    Route::put('branches/{branch}', [BranchController::class, 'update'])->name('admin.branches.update');
    Route::patch('branches/{branch}/status', [BranchController::class, 'status'])->name('admin.branches.status');
    Route::post('orders/{order}/status', [AdminOrderController::class, 'status'])->name('admin.orders.status');
    Route::post('orders/{order}/courier', [AdminOrderController::class, 'assignCourier'])->name('admin.orders.courier');
    Route::post('orders/{order}/branches', [AdminOrderController::class, 'assignBranches'])->name('admin.orders.branches');
    Route::get('merchants', [AdminUserController::class, 'merchants'])->name('admin.merchants');
    Route::get('couriers', [AdminUserController::class, 'couriers'])->name('admin.couriers');
    Route::post('users/{user}/status', [AdminUserController::class, 'status'])->name('admin.users.status');
    Route::get('users/{user}/documents/{document}', [AdminUserController::class, 'showDocument'])->name('admin.users.documents.show');
    Route::post('users/{user}/documents/{document}/review', [AdminUserController::class, 'reviewDocument'])->name('admin.users.documents.review');
    Route::get('finance', [AdminFinanceController::class, 'index'])->name('admin.finance');
    Route::post('finance/requests/{financeRequest}/approve', [AdminFinanceController::class, 'approve'])->name('admin.finance.approve');
    Route::post('finance/requests/{financeRequest}/reject', [AdminFinanceController::class, 'reject'])->name('admin.finance.reject');
    Route::post('finance/settlements', [AdminFinanceController::class, 'recordSettlement'])->name('admin.finance.settlements.store');
    Route::get('notifications', [AdminNotificationController::class, 'index'])->name('admin.notifications');
    Route::post('notifications', [AdminNotificationController::class, 'store'])->name('admin.notifications.store');
    Route::get('settings', [AdminSettingsController::class, 'index'])->name('admin.settings');
    Route::post('settings', [AdminSettingsController::class, 'update'])->name('admin.settings.update');
    Route::get('chat', [ChatController::class, 'adminIndex'])->name('admin.chat');
    Route::get('chat/{chat}', [ChatController::class, 'adminShow'])->name('admin.chat.show');
    Route::get('chat/{chat}/messages', [ChatController::class, 'adminMessages'])->name('admin.chat.messages');
    Route::post('chat/{chat}/send', [ChatController::class, 'adminSend'])->name('admin.chat.send');
    Route::post('preferences/theme', [AdminPreferencesController::class, 'theme'])->name('admin.preferences.theme');
    Route::post('preferences/locale', [AdminPreferencesController::class, 'locale'])->name('admin.preferences.locale');
});

Route::get('/admin', fn () => redirect()->route('admin.dashboard'))->middleware(['dashboard.host', 'auth', 'active', 'role:admin']);
