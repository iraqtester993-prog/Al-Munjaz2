<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' || app()->getLocale() === 'ku' ? 'rtl' : 'ltr' }}">
@php($isDashboard = strtolower(request()->getHost()) === strtolower((string) config('app.product_admin_host')))
@php($pwaManifestPath = public_path('build/manifest.json'))
@php($pwaBuildHash = is_file($pwaManifestPath) ? substr(sha1_file($pwaManifestPath), 0, 10) : 'dev')
@php($pwaVersion = config('app.pwa_version').'-'.$pwaBuildHash)
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>{{ config('app.name') }}{{ $isDashboard ? ' — لوحة الإدارة' : '' }}</title>
    <meta name="theme-color" content="#0B6E68">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-touch-fullscreen" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="format-detection" content="telephone=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
    @if (! $isDashboard)
        <link rel="manifest" href="{{ url('/pwa/manifest?v='.$pwaVersion) }}">
        <link rel="apple-touch-icon" href="{{ asset('assets/icon-180.png') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('assets/icon-192.png') }}">
        <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('assets/icon-512.png') }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('assets/navigation-fix-v4.css').'?v='.$pwaVersion }}">
    @inertiaHead
</head>
<body data-theme="{{ auth()->user()?->theme ?? 'light' }}">
    <script>
        // The installed Android wrapper exposes this optional bridge. Browser
        // and PWA users do not have it, so keep it entirely non-blocking.
        if (window.NativeApp?.postMessage) {
            @auth
            window.NativeApp.postMessage('login:{{ 'almunjaz-user-'.auth()->id() }}');
            @else
            window.NativeApp.postMessage('logout');
            @endauth
        }

        window.OneSignalDeferred = window.OneSignalDeferred || [];
        window.OneSignalDeferred.push(async function (OneSignal) {
            const oneSignalAppId = @json(config('onesignal.app_id'));
            const oneSignalWebEnabled = @json(config('onesignal.web_enabled'));
            if (!oneSignalAppId || !oneSignalWebEnabled) return;

            await OneSignal.init({
                appId: oneSignalAppId,
                serviceWorkerPath: '/push/onesignal/OneSignalSDKWorker.js',
                serviceWorkerParam: { scope: '/push/onesignal/' },
            });
            @auth
            await OneSignal.login(@json('almunjaz-user-'.auth()->id()));
            @endauth
        });

        // The key is designed to be public. The Pusher secret stays only in
        // the server environment and is never exposed to a browser bundle.
        window.__almunjazRealtime = @json([
            'key' => config('pusher-chat.key'),
            'cluster' => config('pusher-chat.cluster'),
        ]);
    </script>
    @inertia
    @if (! $isDashboard)
        <script>
            if ('serviceWorker' in navigator) {
                // A standalone PWA can keep an old JavaScript bundle after a
                // deployment.  Registering the versioned worker without HTTP
                // cache reuse, then reloading once when it takes control,
                // makes the next screen use the matching current bundle.
                const pwaReloadKey = 'almunjaz:pwa-worker-reloaded:{{ $pwaVersion }}';

                navigator.serviceWorker.addEventListener('controllerchange', () => {
                    try {
                        if (sessionStorage.getItem(pwaReloadKey)) return;
                        sessionStorage.setItem(pwaReloadKey, '1');
                    } catch (_) {
                        // Reloading once is still preferable to retaining an
                        // incompatible cached interface when storage is off.
                    }

                    window.location.reload();
                });

                const pwaRecoveryRequested = new URLSearchParams(window.location.search).has('pwa_recover');

                const recoverLegacyPwa = async () => {
                    // This one-time route is deliberately query-based so an
                    // old cache-first worker cannot match its cached login or
                    // registration response. It removes that obsolete worker
                    // and its stored files before the current worker is
                    // registered on the next clean page load.
                    try {
                        const registrations = await navigator.serviceWorker.getRegistrations();
                        await Promise.all(registrations.map((registration) => registration.unregister()));

                        if ('caches' in window) {
                            const cacheNames = await caches.keys();
                            await Promise.all(cacheNames.map((cacheName) => caches.delete(cacheName)));
                        }
                    } catch (_) {
                        // Continue to the fresh versioned registration even if
                        // a browser blocks cache inspection.
                    }

                    const cleanUrl = new URL(window.location.href);
                    cleanUrl.searchParams.delete('pwa_recover');
                    cleanUrl.searchParams.set('pwa_recovered', '{{ $pwaVersion }}');
                    window.location.replace(cleanUrl.toString());
                };

                window.addEventListener('load', () => {
                    if (pwaRecoveryRequested) {
                        recoverLegacyPwa();
                        return;
                    }

                    navigator.serviceWorker
                        .register('/pwa/worker?v={{ $pwaVersion }}', { scope: '/', updateViaCache: 'none' })
                        .then((registration) => registration.update())
                        .catch(() => {});
                });
            }
        </script>
    @endif
</body>
</html>
