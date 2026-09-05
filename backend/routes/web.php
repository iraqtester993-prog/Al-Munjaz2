<?php

use App\Http\Controllers\Admin\AdminBranchTransferController;
use App\Http\Controllers\Admin\AdminCashboxController;
use App\Http\Controllers\Admin\AdminCourierLocationController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminEmployeeController;
use App\Http\Controllers\Admin\AdminFinanceController;
use App\Http\Controllers\Admin\AdminLoyaltyController;
use App\Http\Controllers\Admin\AdminMobileContentController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminPermissionProfileController;
use App\Http\Controllers\Admin\AdminPlatformController;
use App\Http\Controllers\Admin\AdminPreferencesController;
use App\Http\Controllers\Admin\AdminPricingController;
use App\Http\Controllers\Admin\AdminProvinceController;
use App\Http\Controllers\Admin\AdminReportsController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\BranchPortalController;
use App\Http\Controllers\App\AppOrderController;
use App\Http\Controllers\App\AppProfileController;
use App\Http\Controllers\App\AppReportController;
use App\Http\Controllers\App\AppWalletController;
use App\Http\Controllers\App\ChatController;
use App\Http\Controllers\App\CourierLocationController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\NotificationController;
use App\Http\Controllers\App\PushSubscriptionController;
use App\Http\Controllers\App\PusherChatAuthorizationController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\LocaleController;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

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
    $manifestPath = public_path('build/manifest.json');
    $buildHash = is_file($manifestPath) ? substr(sha1_file($manifestPath), 0, 10) : 'dev';
    $pwaVersion = (string) config('app.pwa_version').'-'.$buildHash;

    $worker = str_replace(
        '__PWA_VERSION__',
        $pwaVersion,
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

// cPanel does not follow the release-to-shared-storage symbolic link for
// public files. Serve the small, public slider images through Laravel so the
// dashboard and the installed app receive the exact same reliable URL.
Route::get('/media/mobile-slides/{filename}', function (string $filename) {
    abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) === 1, 404);

    $path = 'mobile-slides/'.$filename;
    abort_unless(Storage::disk('public')->exists($path), 404);

    return Storage::disk('public')->response($path, null, [
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->name('media.mobile-slide');

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
*/
Route::get('/', function (Request $request) {
    return strtolower($request->getHost()) === strtolower((string) config('app.product_admin_host'))
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
Route::post('/chat/pusher-auth', PusherChatAuthorizationController::class)->middleware(['auth', 'active']);

// Public legal pages remain readable before and after sign-in. The legal body
// is only sent when a visitor opens one of these pages, not with every app
// navigation.
Route::get('/privacy-policy', fn () => Inertia::render('Auth/Legal', [
    'documentType' => 'privacy',
    'legalContent' => Setting::publicContent(),
]))
    ->name('legal.privacy');
Route::get('/terms-of-use', fn () => Inertia::render('Auth/Legal', [
    'documentType' => 'terms',
    'legalContent' => Setting::publicContent(),
]))
    ->name('legal.terms');

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

    // A direct order has one accountable courier from pickup through
    // delivery. Retired specialist/transporter accounts stay outside this
    // customer-facing application surface.
    Route::get('/app', [DashboardController::class, 'app'])->name('app')->middleware('role:merchant,courier');

    Route::get('/app/profile', [AppProfileController::class, 'index'])->name('app.profile')->middleware('role:merchant,courier');
    Route::post('/profile/update', [AppProfileController::class, 'update'])->middleware('role:merchant,courier')->name('profile.update');
    Route::post('/profile/theme', [AppProfileController::class, 'theme'])->middleware('role:merchant,courier')->name('profile.theme');
    Route::post('/profile/locale', [AppProfileController::class, 'locale'])->middleware('role:merchant,courier')->name('profile.locale');
    Route::get('/profile/documents/{document}', [AppProfileController::class, 'showDocument'])->middleware('role:merchant,courier')->name('profile.documents.show');
    Route::post('/profile/documents/{document}', [AppProfileController::class, 'replaceDocument'])
        ->middleware('role:courier')
        ->name('profile.documents.replace');
    Route::post('/profile/verification', [AppProfileController::class, 'verification'])->middleware('role:merchant')->name('profile.verification');

    /*
    |--------------------------------------------------------------------------
    | Merchant & courier shared resources
    |--------------------------------------------------------------------------
    */
    Route::prefix('app')->middleware('role:merchant,courier')->group(function () {
        Route::post('duty', [DashboardController::class, 'duty'])->name('app.duty');
        // The phone asks the operating system for location permission. This
        // endpoint only receives the current, consented position and replaces
        // the prior one; it never stores a courier route history.
        Route::post('location', [CourierLocationController::class, 'store'])
            ->middleware(['role:courier', 'throttle:120,1'])
            ->name('app.location.update');
        Route::delete('location', [CourierLocationController::class, 'destroy'])
            ->middleware('role:courier')
            ->name('app.location.clear');
        Route::get('orders', [AppOrderController::class, 'index'])->name('app.orders');
        Route::get('reports', [AppReportController::class, 'index'])->name('app.reports')->middleware('role:merchant,courier');
        Route::post('orders', [AppOrderController::class, 'store'])->name('app.orders.store');
        Route::post('orders/{order}/update', [AppOrderController::class, 'update'])->name('app.orders.update');
        Route::delete('orders/{order}', [AppOrderController::class, 'destroy'])->name('app.orders.destroy');
        Route::post('orders/{order}/status', [AppOrderController::class, 'status'])->name('app.orders.status');
        Route::post('orders/{order}/return', [AppOrderController::class, 'startReturn'])->name('app.orders.return');
        Route::post('orders/{order}/return-to-merchant', [AppOrderController::class, 'confirmReturnToMerchant'])->name('app.orders.return-to-merchant');
        Route::post('orders/{order}/recreate', [AppOrderController::class, 'recreate'])->name('app.orders.recreate');
        Route::post('orders/{order}/republish', [AppOrderController::class, 'republish'])->name('app.orders.republish');
        Route::post('orders/{order}/archive', [AppOrderController::class, 'archive'])->name('app.orders.archive');
        Route::post('orders/{order}/claim', [AppOrderController::class, 'claim'])->name('app.orders.claim');
        Route::get('wallet', [AppWalletController::class, 'index'])->name('app.wallet');
        Route::post('wallet/withdraw', [AppWalletController::class, 'withdraw'])->name('app.wallet.withdraw');
        Route::post('wallet/handover', [AppWalletController::class, 'handover'])->name('app.wallet.handover');
        Route::post('wallet/recharge', [AppWalletController::class, 'recharge'])->name('app.wallet.recharge');
        Route::post('wallet/budget', [AppWalletController::class, 'budget'])->name('app.wallet.budget');
        Route::post('wallet/budget/reduce', [AppWalletController::class, 'reduceBudget'])->name('app.wallet.budget.reduce');
        Route::get('chats', [ChatController::class, 'index'])->name('app.chats');
        Route::get('chats/unread', [ChatController::class, 'unread'])->name('app.chats.unread');
        Route::get('chats/{chat}', [ChatController::class, 'show'])->name('app.chats.show');
        Route::get('chats/{chat}/messages', [ChatController::class, 'messages'])->name('app.chats.messages');
        Route::post('chats/{chat}/presence', [ChatController::class, 'presence'])->name('app.chats.presence');
        Route::post('chats/{chat}/send', [ChatController::class, 'send'])->name('app.chats.send');
        Route::post('chats/open', [ChatController::class, 'open'])->name('app.chats.open');
        Route::get('notifications', [NotificationController::class, 'index'])->name('app.notifications');
        Route::get('notifications/feed', [NotificationController::class, 'feed'])->name('app.notifications.feed');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('app.notifications.read-all');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'read'])->name('app.notifications.read');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('app.notifications.destroy');
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
Route::prefix('dashboard')->middleware(['dashboard.host', 'auth', 'active', 'role:admin,branch_manager', 'branch.dashboard.scope'])->group(function () {
    // This terminal route intentionally carries no dashboard data. It is the
    // safe landing state for a newly invited operator before a super admin
    // assigns a named permission profile.
    Route::get('access-denied', fn () => Inertia::render('Admin/AccessDenied'))->name('admin.access-denied');
    // The aggregate landing response contains data from several modules, so
    // it remains super-admin-only until it has a separately filtered shape.
    Route::get('/', [AdminDashboardController::class, 'index'])->middleware('dashboard.super-admin')->name('admin.dashboard');
    Route::get('orders', [AdminOrderController::class, 'index'])->middleware('dashboard.permission:orders.view')->name('admin.orders');
    Route::put('orders/{order}', [AdminOrderController::class, 'update'])->middleware('dashboard.permission:orders.edit')->name('admin.orders.update');
    Route::delete('orders/{order}', [AdminOrderController::class, 'destroy'])->middleware('dashboard.permission:orders.delete')->name('admin.orders.destroy');
    Route::get('branches', [BranchController::class, 'index'])->middleware('dashboard.permission:branches.view')->name('admin.branches');
    Route::post('branches', [BranchController::class, 'store'])->middleware('dashboard.permission:branches.create')->name('admin.branches.store');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('dashboard.permission:branches.edit')->name('admin.branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('dashboard.permission:branches.delete')->name('admin.branches.destroy');
    Route::patch('branches/{branch}/status', [BranchController::class, 'status'])->middleware('dashboard.permission:branches.change_status')->name('admin.branches.status');
    Route::post('branches/{branch}/access', [BranchController::class, 'storeAccess'])->middleware('dashboard.permission:branches.manage_access')->name('admin.branches.access.store');
    Route::put('branches/{branch}/access/{account}', [BranchController::class, 'updateAccess'])->middleware('dashboard.permission:branches.manage_access')->name('admin.branches.access.update');
    Route::post('orders/{order}/status', [AdminOrderController::class, 'status'])->middleware('dashboard.permission:orders.change_status')->name('admin.orders.status');
    Route::post('orders/{order}/courier', [AdminOrderController::class, 'assignCourier'])->middleware('dashboard.permission:orders.assign_courier')->name('admin.orders.courier');
    Route::post('orders/{order}/reoffer-overdue-pickup', [AdminOrderController::class, 'reofferOverduePickup'])->middleware('dashboard.permission:orders.reoffer_overdue_pickup')->name('admin.orders.reoffer-overdue-pickup');
    Route::post('orders/{order}/branches', [AdminOrderController::class, 'assignBranches'])->middleware('dashboard.permission:orders.assign_branches')->name('admin.orders.branches');
    Route::post('orders/{orderId}/restore', [AdminOrderController::class, 'restore'])->middleware('dashboard.permission:orders.restore')->name('admin.orders.restore');
    Route::get('merchants', [AdminUserController::class, 'merchants'])->middleware('dashboard.permission:merchants.view')->name('admin.merchants');
    Route::get('couriers', [AdminUserController::class, 'couriers'])->middleware('dashboard.permission:couriers.view')->name('admin.couriers');
    Route::get('couriers/locations', [AdminCourierLocationController::class, 'index'])->middleware('dashboard.permission:courier_locations.view')->name('admin.couriers.locations');
    Route::put('users/{user}', [AdminUserController::class, 'update'])->middleware('dashboard.user-permission:edit')->name('admin.users.update');
    Route::patch('users/{user}/courier-deduction', [AdminUserController::class, 'updateCourierDeduction'])->middleware('dashboard.user-permission:update_deduction')->name('admin.users.courier-deduction.update');
    Route::post('users/{user}/status', [AdminUserController::class, 'status'])->middleware('dashboard.user-permission:change_status')->name('admin.users.status');
    Route::post('users/{user}/merchant-verification', [AdminUserController::class, 'merchantVerification'])->middleware('dashboard.user-permission:verify')->name('admin.users.merchant-verification');
    Route::post('users/{user}/courier-verification', [AdminUserController::class, 'courierVerification'])->middleware('dashboard.user-permission:verify')->name('admin.users.courier-verification');
    Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->middleware('dashboard.user-permission:delete')->name('admin.users.destroy');
    Route::get('users/{user}/documents/{document}', [AdminUserController::class, 'showDocument'])->middleware('dashboard.user-permission:documents_view')->name('admin.users.documents.show');
    Route::post('users/{user}/documents/{document}/review', [AdminUserController::class, 'reviewDocument'])->middleware('dashboard.user-permission:documents_review')->name('admin.users.documents.review');
    Route::get('finance', [AdminFinanceController::class, 'index'])->middleware('dashboard.permission:finance.view')->name('admin.finance');
    Route::post('finance/requests/{financeRequest}/approve', [AdminFinanceController::class, 'approve'])->middleware('dashboard.permission:finance.approve')->name('admin.finance.approve');
    Route::post('finance/requests/{financeRequest}/reject', [AdminFinanceController::class, 'reject'])->middleware('dashboard.permission:finance.reject')->name('admin.finance.reject');
    Route::post('finance/settlements', [AdminFinanceController::class, 'recordSettlement'])->middleware('dashboard.permission:finance.record_settlement')->name('admin.finance.settlements.store');
    Route::get('cashboxes', [AdminCashboxController::class, 'index'])->middleware('dashboard.permission:cashboxes.view')->name('admin.cashboxes');
    Route::post('cashboxes', [AdminCashboxController::class, 'store'])->middleware('dashboard.permission:cashboxes.create')->name('admin.cashboxes.store');
    Route::post('cashboxes/voucher', [AdminCashboxController::class, 'voucher'])->middleware('dashboard.permission:cashboxes.transfer')->name('admin.cashboxes.voucher');
    Route::post('cashboxes/transfer', [AdminCashboxController::class, 'transfer'])->middleware('dashboard.permission:cashboxes.transfer')->name('admin.cashboxes.transfer');
    Route::patch('cashboxes/{cashbox}/status', [AdminCashboxController::class, 'status'])->middleware('dashboard.permission:cashboxes.change_status')->name('admin.cashboxes.status');
    Route::get('pricing', [AdminPricingController::class, 'index'])->middleware('dashboard.permission:pricing.view')->name('admin.pricing');
    Route::post('pricing', [AdminPricingController::class, 'store'])->middleware('dashboard.permission:pricing.create')->name('admin.pricing.store');
    Route::put('pricing/{pricingRule}', [AdminPricingController::class, 'update'])->middleware('dashboard.permission:pricing.edit')->name('admin.pricing.update');
    Route::patch('pricing/{pricingRule}/status', [AdminPricingController::class, 'status'])->middleware('dashboard.permission:pricing.change_status')->name('admin.pricing.status');
    Route::get('reports', [AdminReportsController::class, 'index'])->middleware('dashboard.permission:reports.view')->name('admin.reports');
    Route::get('platform', [AdminPlatformController::class, 'index'])->middleware('dashboard.permission:platform.view')->name('admin.platform');
    Route::post('platform/companies', [AdminPlatformController::class, 'storeCompany'])->middleware('dashboard.permission:platform.companies_create')->name('admin.platform.companies.store');
    Route::put('platform/companies/{tenant}', [AdminPlatformController::class, 'updateCompany'])->middleware('dashboard.permission:platform.companies_edit')->name('admin.platform.companies.update');
    Route::post('platform/plans', [AdminPlatformController::class, 'storePlan'])->middleware('dashboard.permission:platform.plans_create')->name('admin.platform.plans.store');
    Route::put('platform/plans/{plan}', [AdminPlatformController::class, 'updatePlan'])->middleware('dashboard.permission:platform.plans_edit')->name('admin.platform.plans.update');
    Route::post('platform/subscriptions', [AdminPlatformController::class, 'storeSubscription'])->middleware('dashboard.permission:platform.subscriptions_create')->name('admin.platform.subscriptions.store');
    Route::patch('platform/subscriptions/{subscription}', [AdminPlatformController::class, 'updateSubscriptionStatus'])->middleware('dashboard.permission:platform.subscriptions_change_status')->name('admin.platform.subscriptions.status');
    Route::post('platform/invoices', [AdminPlatformController::class, 'storeInvoice'])->middleware('dashboard.permission:platform.invoices_create')->name('admin.platform.invoices.store');
    Route::patch('platform/invoices/{invoice}', [AdminPlatformController::class, 'updateInvoiceStatus'])->middleware('dashboard.permission:platform.invoices_change_status')->name('admin.platform.invoices.status');
    Route::post('platform/invitations', [AdminPlatformController::class, 'invite'])->middleware('dashboard.super-admin')->name('admin.platform.invitations.store');
    Route::get('notifications', [AdminNotificationController::class, 'index'])->middleware('dashboard.permission:notifications.view')->name('admin.notifications');
    Route::post('notifications', [AdminNotificationController::class, 'store'])->middleware('dashboard.permission:notifications.send')->name('admin.notifications.store');
    Route::get('settings', [AdminSettingsController::class, 'index'])->middleware('dashboard.settings-access')->name('admin.settings');
    // Keep this endpoint for older compiled browsers. Only a legacy profile
    // carrying settings.update can call it; new pages use the scoped routes.
    Route::post('settings', [AdminSettingsController::class, 'update'])->middleware('dashboard.permission:settings.update')->name('admin.settings.update');
    Route::post('settings/branding', [AdminSettingsController::class, 'updateBranding'])->middleware('dashboard.permission:settings.update_branding')->name('admin.settings.branding.update');
    Route::post('settings/support', [AdminSettingsController::class, 'updateSupport'])->middleware('dashboard.permission:settings.update_support')->name('admin.settings.support.update');
    Route::post('settings/financial-defaults', [AdminSettingsController::class, 'updateFinancialDefaults'])->middleware('dashboard.permission:settings.update_financial_defaults')->name('admin.settings.financial-defaults.update');
    Route::post('settings/courier-deduction-default', [AdminSettingsController::class, 'updateCourierDeductionDefault'])->middleware('dashboard.permission:settings.update_courier_deduction_default')->name('admin.settings.courier-deduction-default.update');
    Route::post('settings/timing', [AdminSettingsController::class, 'updateTiming'])->middleware('dashboard.permission:settings.update_timing')->name('admin.settings.timing.update');
    Route::post('settings/public-content', [AdminSettingsController::class, 'updatePublicContent'])->middleware('dashboard.permission:settings.update_public_content')->name('admin.settings.public-content.update');
    Route::post('settings/provinces', [AdminProvinceController::class, 'store'])->middleware('dashboard.permission:provinces.create')->name('admin.provinces.store');
    Route::put('settings/provinces/{province}', [AdminProvinceController::class, 'update'])->middleware('dashboard.permission:provinces.edit')->name('admin.provinces.update');
    Route::patch('settings/provinces/{province}/status', [AdminProvinceController::class, 'status'])->middleware('dashboard.permission:provinces.change_status')->name('admin.provinces.status');
    // Slider content is managed inside Settings; keep its existing, granular
    // content permissions for mutations rather than creating a second page.
    Route::post('settings/slides', [AdminMobileContentController::class, 'store'])->middleware('dashboard.permission:content.create')->name('admin.settings.slides.store');
    Route::put('settings/slides/{mobileSlide}', [AdminMobileContentController::class, 'update'])->middleware('dashboard.permission:content.edit')->name('admin.settings.slides.update');
    Route::delete('settings/slides/{mobileSlide}', [AdminMobileContentController::class, 'destroy'])->middleware('dashboard.permission:content.delete')->name('admin.settings.slides.destroy');
    Route::get('loyalty', [AdminLoyaltyController::class, 'index'])->middleware('dashboard.permission:loyalty.view')->name('admin.loyalty');
    Route::post('loyalty/settings', [AdminLoyaltyController::class, 'store'])->middleware('dashboard.permission:loyalty.update_reward_setting')->name('admin.loyalty.settings');
    Route::post('loyalty/adjust', [AdminLoyaltyController::class, 'adjust'])->middleware('dashboard.permission:loyalty.adjust_points')->name('admin.loyalty.adjust');
    Route::get('chat', [ChatController::class, 'adminIndex'])->middleware('dashboard.permission:chat.view')->name('admin.chat');
    Route::get('chat/{chat}', [ChatController::class, 'adminShow'])->middleware('dashboard.permission:chat.view')->name('admin.chat.show');
    Route::get('chat/{chat}/messages', [ChatController::class, 'adminMessages'])->middleware('dashboard.permission:chat.view')->name('admin.chat.messages');
    Route::post('chat/{chat}/send', [ChatController::class, 'adminSend'])->middleware('dashboard.permission:chat.reply')->name('admin.chat.send');
    Route::post('preferences/theme', [AdminPreferencesController::class, 'theme'])->name('admin.preferences.theme');
    Route::post('preferences/locale', [AdminPreferencesController::class, 'locale'])->name('admin.preferences.locale');

    // Platform staff remains super-admin-only in its controller. The same
    // screen is available to a branch principal manager through the local
    // employee permission module, where every account/profile is branch
    // scoped server-side.
    Route::get('employees', [AdminEmployeeController::class, 'index'])->middleware('dashboard.permission:employees.view')->name('admin.employees');
    Route::post('employees', [AdminEmployeeController::class, 'store'])->middleware('dashboard.permission:employees.create')->name('admin.employees.store');
    Route::post('employees/invitations', [AdminEmployeeController::class, 'invite'])->middleware('dashboard.permission:employees.create')->name('admin.employees.invitations.store');
    Route::put('employees/{user}', [AdminEmployeeController::class, 'update'])->middleware('dashboard.permission:employees.edit')->name('admin.employees.update');
    Route::patch('employees/{user}/status', [AdminEmployeeController::class, 'status'])->middleware('dashboard.permission:employees.change_status')->name('admin.employees.status');
    Route::delete('employees/{user}', [AdminEmployeeController::class, 'destroy'])->middleware('dashboard.permission:employees.delete')->name('admin.employees.destroy');

    // The transfer console is deliberately kept as its own operational page.
    // AdminShell navigation can expose it when the dashboard information
    // architecture is ready, without coupling transfer permissions to it.
    Route::get('transfers', [AdminBranchTransferController::class, 'index'])->middleware('dashboard.permission:transfers.view')->name('admin.transfers');
    Route::post('transfers', [AdminBranchTransferController::class, 'store'])->middleware('dashboard.permission:transfers.create')->name('admin.transfers.store');
    Route::post('transfers/{transfer}/dispatch', [AdminBranchTransferController::class, 'dispatch'])->middleware('dashboard.permission:transfers.dispatch')->name('admin.transfers.dispatch');
    Route::post('transfers/{transfer}/receive', [AdminBranchTransferController::class, 'receive'])->middleware('dashboard.permission:transfers.receive')->name('admin.transfers.receive');

    // A branch principal can maintain local profiles for its own employees;
    // the controller rejects global profiles and self-escalation. Platform
    // profiles remain restricted to the super administrator there.
    Route::get('permissions', [AdminPermissionProfileController::class, 'index'])->middleware('dashboard.permission:permissions.view')->name('admin.permissions');
    Route::post('permissions', [AdminPermissionProfileController::class, 'store'])->middleware('dashboard.permission:permissions.create')->name('admin.permissions.store');
    Route::put('permissions/{permissionProfile}', [AdminPermissionProfileController::class, 'update'])->middleware('dashboard.permission:permissions.edit')->name('admin.permissions.update');
    Route::delete('permissions/{permissionProfile}', [AdminPermissionProfileController::class, 'destroy'])->middleware('dashboard.permission:permissions.delete')->name('admin.permissions.destroy');
    Route::put('permissions/users/{user}', [AdminPermissionProfileController::class, 'updateAssignment'])->middleware('dashboard.permission:permissions.assign')->name('admin.permissions.assignments.update');
});

// The legacy portal remains for multi-branch owners. Branch managers use the
// unified /dashboard and never bypass its one-branch scope.
Route::prefix('dashboard/branch')
    ->middleware(['dashboard.host', 'auth', 'active', 'role:owner', 'branch.portal.active'])
    ->group(function (): void {
        Route::get('/', [BranchPortalController::class, 'index'])->name('admin.branch.portal');
        Route::post('orders/{order}/status', [BranchPortalController::class, 'statusOrder'])->name('admin.branch.orders.status');
        Route::post('orders/{order}/courier', [BranchPortalController::class, 'assignCourier'])->name('admin.branch.orders.courier');
        Route::post('orders/{order}/reoffer-overdue-pickup', [BranchPortalController::class, 'reofferOverduePickup'])->name('admin.branch.orders.reoffer-overdue-pickup');
        Route::put('users/{user}', [BranchPortalController::class, 'updateUser'])->name('admin.branch.users.update');
        Route::post('users/{user}/status', [BranchPortalController::class, 'statusUser'])->name('admin.branch.users.status');
        Route::post('users/{user}/merchant-verification', [BranchPortalController::class, 'merchantVerification'])->name('admin.branch.users.merchant-verification');
        Route::get('users/{user}/documents/{document}', [BranchPortalController::class, 'showDocument'])->name('admin.branch.users.documents.show');
        Route::post('users/{user}/documents/{document}/review', [BranchPortalController::class, 'reviewDocument'])->name('admin.branch.users.documents.review');
        Route::delete('users/{user}', [BranchPortalController::class, 'destroyUser'])->name('admin.branch.users.destroy');
        Route::post('preferences/theme', [AdminPreferencesController::class, 'theme'])->name('admin.branch.preferences.theme');
        Route::post('preferences/locale', [AdminPreferencesController::class, 'locale'])->name('admin.branch.preferences.locale');
    });

Route::get('/admin', fn (Request $request) => redirect()->to(
    $request->user()->firstAdminDashboardPath() ?? '/dashboard/access-denied'
))->middleware(['dashboard.host', 'auth', 'active', 'role:admin,branch_manager', 'branch.dashboard.scope']);
