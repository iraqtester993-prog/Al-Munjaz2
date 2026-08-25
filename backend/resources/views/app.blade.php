<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' || app()->getLocale() === 'ku' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', config('app.name')) — {{ __('Merchant App') }}</title>
    <meta name="theme-color" content="#0B6E68">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="{{ url('/manifest.json') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/icon-180.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('assets/icon-192.png') }}">
    <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('assets/icon-512.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body data-theme="{{ auth()->user()?->theme ?? 'light' }}">
    @inertia
    <script>
        window.__translations = @json($translations);
        window.__locale = @json(app()->getLocale());
    </script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
        }
    </script>
</body>
</html>
