<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' || app()->getLocale() === 'ku' ? 'rtl' : 'ltr' }}">
@php($isDashboard = strtolower(request()->getHost()) === strtolower((string) config('app.product_admin_host')))
@php($pwaVersion = config('app.pwa_version'))
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
    @if (! $isDashboard)
        <link rel="manifest" href="{{ url('/pwa/manifest?v='.$pwaVersion) }}">
        <link rel="apple-touch-icon" href="{{ asset('assets/icon-180.png') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('assets/icon-192.png') }}">
        <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('assets/icon-512.png') }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body data-theme="{{ auth()->user()?->theme ?? 'light' }}">
    @inertia
    @if (! $isDashboard)
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => navigator.serviceWorker.register('/pwa/worker?v={{ $pwaVersion }}', { scope: '/' }));
            }
        </script>
    @endif
</body>
</html>
