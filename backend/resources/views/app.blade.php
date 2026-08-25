<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' || app()->getLocale() === 'ku' ? 'rtl' : 'ltr' }}">
@php($isDashboard = preg_match('/^(?:dashboard|admin)\./', request()->getHost()))
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>المنجز السريع — {{ $isDashboard ? 'لوحة الإدارة' : __('Merchant App') }}</title>
    <meta name="theme-color" content="#0B6E68">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (! $isDashboard)
        {{-- Versioned URLs bypass the long static-file cache imposed by the host. --}}
        <link rel="manifest" href="{{ url('/manifest.json?v=9') }}">
        <link rel="apple-touch-icon" href="{{ asset('assets/icon-180.png') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('assets/icon-192.png') }}">
        <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('assets/icon-512.png') }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body data-theme="{{ auth()->user()?->theme ?? 'light' }}">
    @inertia
    <script>
        window.__translations = @json($translations);
        window.__locale = @json(app()->getLocale());
    </script>
    @if (! $isDashboard)
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js?v=9'));
            }
        </script>
    @endif
</body>
</html>
