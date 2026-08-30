<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use App\Http\Middleware\ActiveUserMiddleware;
use App\Http\Middleware\DashboardPermissionMiddleware;
use App\Http\Middleware\DashboardSuperAdminMiddleware;
use App\Http\Middleware\DashboardUserPermissionMiddleware;
use App\Http\Middleware\EnsureDashboardHost;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetTenantContext;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(at: function (): array {
            $domain = preg_quote((string) config('app.product_domain'), '/');
            $mobileHost = preg_quote((string) config('app.product_mobile_host'), '/');
            $adminHost = preg_quote((string) config('app.product_admin_host'), '/');

            return [
                '^'.$domain.'$',
                '^www\\.'.$domain.'$',
                '^'.$mobileHost.'$',
                '^'.$adminHost.'$',
                '^(?:app|dashboard)\\.'.$domain.'$',
                '^www\\.(?:mobile|admin)\\.'.$domain.'$',
            ];
        }, subdomains: false);

        $middleware->redirectUsersTo(fn ($request) => match ($request->user()?->role) {
            'admin' => $request->user()->firstAdminDashboardPath() ?? '/dashboard/access-denied',
            'owner', 'branch_manager' => '/dashboard/branch',
            default => '/app',
        });

        $middleware->redirectGuestsTo(fn ($request) => $request->is('dashboard*')
            ? '/dashboard/login'
            : '/login');

        $middleware->web(prepend: [
            EnsureDashboardHost::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            SetTenantContext::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'active' => ActiveUserMiddleware::class,
            'dashboard.host' => EnsureDashboardHost::class,
            'dashboard.permission' => DashboardPermissionMiddleware::class,
            'dashboard.super-admin' => DashboardSuperAdminMiddleware::class,
            'dashboard.user-permission' => DashboardUserPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A PHP-level post_max_size rejection happens before an upload
        // controller can validate individual documents. Convert it to a
        // useful form error instead of a generic server page. A reverse-proxy
        // 413 is prevented by browser-side image preparation.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->is('register')) {
                return redirect()->route('register', ['role' => 'courier'])
                    ->withErrors(['documents' => __('auth.courier_upload_request_too_large')]);
            }

            if ($request->is('profile/verification')) {
                $message = __('auth.merchant_verification_upload_request_too_large');

                return redirect()->route('app.profile')
                    ->withErrors(['documents' => $message])
                    ->with('error', $message);
            }

            return null;
        });
    })->create();
