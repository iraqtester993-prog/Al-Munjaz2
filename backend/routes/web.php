<?php

use App\Http\Controllers\Admin\AdminBranchTransferController;
use App\Http\Controllers\Admin\AdminCashboxController;
use App\Http\Controllers\Admin\AdminCourierLocationController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFinanceController;
use App\Http\Controllers\Admin\AdminMobileContentController;
use App\Http\Controllers\Admin\AdminLoyaltyController;
use App\Http\Controllers\Admin\BranchPortalController;
use App\Http\Controllers\Admin\BranchMobileContentController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminPlatformController;
use App\Http\Controllers\Admin\AdminPreferencesController;
use App\Http\Controllers\Admin\AdminPricingController;
use App\Http\Controllers\Admin\AdminReportsController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\App\AppOrderController;
use App\Http\Controllers\App\AppProfileController;
use App\Http\Controllers\App\AppReportController;
use App\Http\Controllers\App\AppWalletController;
use App\Http\Controllers\App\ChatController;
use App\Http\Controllers\App\CourierLocationController;
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
    Route::get('/dashboard/invitations/{token}', [AdminPlatformController::class, 'invitationForm'])
        ->middleware('dashboard.host')
        ->name('admin.invitations.accept');
    Route::post('/dashboard/invitations/{token}', [AdminPlatformController::class, 'acceptInvitation'])
        ->middleware('dashboard.host')
        ->name('admin.invitations.accept.store');
});

/*
|--------------------------------------------------------------------------
| Authenticated app routes (merchant + courier + admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/app', [DashboardController::class, 'app'])->name('app')->middleware('role:merchant,courier,pickup_courier,delivery_courier,transporter');

    Route::get('/app/profile', [AppProfileController::class, 'index'])->name('app.profile')->middleware('role:merchant,courier,pickup_courier,delivery_courier,transporter');
    Route::post('/profile/update', [AppProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/theme', [AppProfileController::class, 'theme'])->name('profile.theme');
    Route::post('/profile/locale', [AppProfileController::class, 'locale'])->name('profile.locale');
    Route::post('/profile/verification', [AppProfileController::class, 'verification'])->name('profile.verification')->middleware('role:merchant');

    /*
    |--------------------------------------------------------------------------
    | Merchant & courier shared resources
    |--------------------------------------------------------------------------
    */
    Route::prefix('app')->middleware('role:merchant,courier,pickup_courier,delivery_courier,transporter')->group(function () {
        Route::post('duty', [DashboardController::class, 'duty'])->name('app.duty');
        // The phone asks the operating system for location permission. This
        // endpoint only receives the current, consented position and replaces
        // the prior one; it never stores a courier route history.
        Route::post('location', [CourierLocationController::class, 'store'])
            ->middleware(['role:courier,pickup_courier,delivery_courier,transporter', 'throttle:120,1'])
            ->name('app.location.update');
        Route::delete('location', [CourierLocationController::class, 'destroy'])
            ->middleware('role:courier,pickup_courier,delivery_courier,transporter')
            ->name('app.location.clear');
        Route::get('orders', [AppOrderController::class, 'index'])->name('app.orders');
        Route::get('reports', [AppReportController::class, 'index'])->name('app.reports')->middleware('role:merchant');
        Route::post('orders', [AppOrderController::class, 'store'])->name('app.orders.store');
        Route::post('orders/{order}/update', [AppOrderController::class, 'update'])->name('app.orders.update');
        Route::post('orders/{order}/status', [AppOrderController::class, 'status'])->name('app.orders.status');
        Route::post('orders/{order}/return', [AppOrderController::class, 'startReturn'])->name('app.orders.return');
        Route::post('orders/{order}/return-to-merchant', [AppOrderController::class, 'confirmReturnToMerchant'])->name('app.orders.return-to-merchant');
        Route::post('orders/{order}/recreate', [AppOrderController::class, 'recreate'])->name('app.orders.recreate');
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
    Route::post('branches/{branch}/access', [BranchController::class, 'storeAccess'])->name('admin.branches.access.store');
    Route::post('orders/{order}/status', [AdminOrderController::class, 'status'])->name('admin.orders.status');
    Route::post('orders/{order}/courier', [AdminOrderController::class, 'assignCourier'])->name('admin.orders.courier');
    Route::post('orders/{order}/branches', [AdminOrderController::class, 'assignBranches'])->name('admin.orders.branches');
    Route::get('merchants', [AdminUserController::class, 'merchants'])->name('admin.merchants');
    Route::get('couriers', [AdminUserController::class, 'couriers'])->name('admin.couriers');
    Route::get('couriers/locations', [AdminCourierLocationController::class, 'index'])->name('admin.couriers.locations');
    Route::put('users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
    Route::post('users/{user}/status', [AdminUserController::class, 'status'])->name('admin.users.status');
    Route::get('users/{user}/documents/{document}', [AdminUserController::class, 'showDocument'])->name('admin.users.documents.show');
    Route::post('users/{user}/documents/{document}/review', [AdminUserController::class, 'reviewDocument'])->name('admin.users.documents.review');
    Route::get('finance', [AdminFinanceController::class, 'index'])->name('admin.finance');
    Route::post('finance/requests/{financeRequest}/approve', [AdminFinanceController::class, 'approve'])->name('admin.finance.approve');
    Route::post('finance/requests/{financeRequest}/reject', [AdminFinanceController::class, 'reject'])->name('admin.finance.reject');
    Route::post('finance/settlements', [AdminFinanceController::class, 'recordSettlement'])->name('admin.finance.settlements.store');
    Route::get('cashboxes', [AdminCashboxController::class, 'index'])->name('admin.cashboxes');
    Route::post('cashboxes', [AdminCashboxController::class, 'store'])->name('admin.cashboxes.store');
    Route::post('cashboxes/voucher', [AdminCashboxController::class, 'voucher'])->name('admin.cashboxes.voucher');
    Route::post('cashboxes/transfer', [AdminCashboxController::class, 'transfer'])->name('admin.cashboxes.transfer');
    Route::patch('cashboxes/{cashbox}/status', [AdminCashboxController::class, 'status'])->name('admin.cashboxes.status');
    Route::get('pricing', [AdminPricingController::class, 'index'])->name('admin.pricing');
    Route::post('pricing', [AdminPricingController::class, 'store'])->name('admin.pricing.store');
    Route::put('pricing/{pricingRule}', [AdminPricingController::class, 'update'])->name('admin.pricing.update');
    Route::patch('pricing/{pricingRule}/status', [AdminPricingController::class, 'status'])->name('admin.pricing.status');
    Route::get('reports', [AdminReportsController::class, 'index'])->name('admin.reports');
    Route::get('platform', [AdminPlatformController::class, 'index'])->name('admin.platform');
    Route::post('platform/companies', [AdminPlatformController::class, 'storeCompany'])->name('admin.platform.companies.store');
    Route::put('platform/companies/{tenant}', [AdminPlatformController::class, 'updateCompany'])->name('admin.platform.companies.update');
    Route::post('platform/plans', [AdminPlatformController::class, 'storePlan'])->name('admin.platform.plans.store');
    Route::put('platform/plans/{plan}', [AdminPlatformController::class, 'updatePlan'])->name('admin.platform.plans.update');
    Route::post('platform/subscriptions', [AdminPlatformController::class, 'storeSubscription'])->name('admin.platform.subscriptions.store');
    Route::patch('platform/subscriptions/{subscription}', [AdminPlatformController::class, 'updateSubscriptionStatus'])->name('admin.platform.subscriptions.status');
    Route::post('platform/invoices', [AdminPlatformController::class, 'storeInvoice'])->name('admin.platform.invoices.store');
    Route::patch('platform/invoices/{invoice}', [AdminPlatformController::class, 'updateInvoiceStatus'])->name('admin.platform.invoices.status');
    Route::post('platform/invitations', [AdminPlatformController::class, 'invite'])->name('admin.platform.invitations.store');
    Route::get('notifications', [AdminNotificationController::class, 'index'])->name('admin.notifications');
    Route::post('notifications', [AdminNotificationController::class, 'store'])->name('admin.notifications.store');
    Route::get('settings', [AdminSettingsController::class, 'index'])->name('admin.settings');
    Route::post('settings', [AdminSettingsController::class, 'update'])->name('admin.settings.update');
    Route::get('content', [AdminMobileContentController::class, 'index'])->name('admin.content');
    Route::post('content', [AdminMobileContentController::class, 'store'])->name('admin.content.store');
    Route::put('content/{mobileSlide}', [AdminMobileContentController::class, 'update'])->name('admin.content.update');
    Route::delete('content/{mobileSlide}', [AdminMobileContentController::class, 'destroy'])->name('admin.content.destroy');
    Route::get('loyalty', [AdminLoyaltyController::class, 'index'])->name('admin.loyalty');
    Route::post('loyalty/settings', [AdminLoyaltyController::class, 'store'])->name('admin.loyalty.settings');
    Route::post('loyalty/adjust', [AdminLoyaltyController::class, 'adjust'])->name('admin.loyalty.adjust');
    Route::get('chat', [ChatController::class, 'adminIndex'])->name('admin.chat');
    Route::get('chat/{chat}', [ChatController::class, 'adminShow'])->name('admin.chat.show');
    Route::get('chat/{chat}/messages', [ChatController::class, 'adminMessages'])->name('admin.chat.messages');
    Route::post('chat/{chat}/send', [ChatController::class, 'adminSend'])->name('admin.chat.send');
    Route::post('preferences/theme', [AdminPreferencesController::class, 'theme'])->name('admin.preferences.theme');
    Route::post('preferences/locale', [AdminPreferencesController::class, 'locale'])->name('admin.preferences.locale');

    // The transfer console is deliberately kept as its own operational page.
    // AdminShell navigation can expose it when the dashboard information
    // architecture is ready, without coupling transfer permissions to it.
    Route::get('transfers', [AdminBranchTransferController::class, 'index'])->name('admin.transfers');
    Route::post('transfers', [AdminBranchTransferController::class, 'store'])->name('admin.transfers.store');
    Route::post('transfers/{transfer}/dispatch', [AdminBranchTransferController::class, 'dispatch'])->name('admin.transfers.dispatch');
    Route::post('transfers/{transfer}/receive', [AdminBranchTransferController::class, 'receive'])->name('admin.transfers.receive');
});

// Branch owners and managers use the same secure dashboard host and sign-in
// page, but never inherit the platform administrator navigation or data.
Route::get('/dashboard/branch', [BranchPortalController::class, 'index'])
    ->middleware(['dashboard.host', 'auth', 'active', 'role:owner,branch_manager'])
    ->name('admin.branch.portal');
Route::post('/dashboard/branch/preferences/theme', [AdminPreferencesController::class, 'theme'])
    ->middleware(['dashboard.host', 'auth', 'active', 'role:owner,branch_manager'])
    ->name('admin.branch.preferences.theme');
Route::post('/dashboard/branch/preferences/locale', [AdminPreferencesController::class, 'locale'])
    ->middleware(['dashboard.host', 'auth', 'active', 'role:owner,branch_manager'])
    ->name('admin.branch.preferences.locale');
Route::get('/dashboard/branch/content', [BranchMobileContentController::class, 'index'])
    ->middleware(['dashboard.host', 'auth', 'active', 'role:owner,branch_manager'])
    ->name('admin.branch.content');
Route::post('/dashboard/branch/content', [BranchMobileContentController::class, 'store'])
    ->middleware(['dashboard.host', 'auth', 'active', 'role:owner,branch_manager'])
    ->name('admin.branch.content.store');
Route::put('/dashboard/branch/content/{mobileSlide}', [BranchMobileContentController::class, 'update'])
    ->middleware(['dashboard.host', 'auth', 'active', 'role:owner,branch_manager'])
    ->name('admin.branch.content.update');
Route::delete('/dashboard/branch/content/{mobileSlide}', [BranchMobileContentController::class, 'destroy'])
    ->middleware(['dashboard.host', 'auth', 'active', 'role:owner,branch_manager'])
    ->name('admin.branch.content.destroy');

Route::get('/admin', fn () => redirect()->route('admin.dashboard'))->middleware(['dashboard.host', 'auth', 'active', 'role:admin']);
