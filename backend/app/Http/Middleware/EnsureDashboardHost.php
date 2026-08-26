<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardHost
{
    /**
     * Keep administration routes isolated from the public application host.
     * This also repairs older installed PWAs that may still open /dashboard.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isDashboardPath = $request->is('dashboard') || $request->is('dashboard/*') || $request->is('admin');

        if (! app()->environment(['local', 'testing'])) {
            $host = strtolower($request->getHost());
            $domain = (string) config('app.product_domain');
            $mobileHost = (string) config('app.product_mobile_host');
            $adminHost = (string) config('app.product_admin_host');

            if ($canonicalHost = $this->canonicalHostForLegacyHost($host, $domain, $mobileHost, $adminHost)) {
                return redirect()->away('https://'.$canonicalHost.$request->getRequestUri(), 302);
            }

            // The bare product domain is not a third application surface.
            // It deliberately leads users to the public mobile sign-in page.
            if (in_array($host, [$domain, 'www.'.$domain], true)) {
                return redirect()->away($this->mobileLoginUrl(), 302);
            }

            $isDashboardHost = $host === $adminHost;

            if ($isDashboardPath && ! $isDashboardHost) {
                return redirect('/login');
            }

            if ($isDashboardHost && $request->is('login')) {
                return redirect('/dashboard/login');
            }

            // A stale shared-domain session cookie used to let a merchant
            // render the public app under admin.our-qiq.com.  Keep the two
            // products isolated even before the role middleware is reached.
            if ($isDashboardHost && $this->isPublicAppPath($request)) {
                if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
                    abort(404);
                }

                return redirect()->away($this->mobileLoginUrl(), 302);
            }
        }

        return $next($request);
    }

    private function isPublicAppPath(Request $request): bool
    {
        return $request->is('login')
            || $request->is('register')
            || $request->is('register/*')
            || $request->is('verify-otp')
            || $request->is('resend-otp')
            || $request->is('app')
            || $request->is('app/*')
            || $request->is('profile/*')
            || $request->is('pwa/manifest')
            || $request->is('pwa/worker')
            || $request->is('manifest.json')
            || $request->is('sw.js');
    }

    private function mobileLoginUrl(): string
    {
        return 'https://'.config('app.product_mobile_host').'/login';
    }

    private function canonicalHostForLegacyHost(string $host, string $domain, string $mobileHost, string $adminHost): ?string
    {
        return match ($host) {
            'app.'.$domain, 'www.mobile.'.$domain => $mobileHost,
            'dashboard.'.$domain, 'www.admin.'.$domain => $adminHost,
            default => null,
        };
    }
}
