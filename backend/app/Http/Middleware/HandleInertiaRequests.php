<?php

namespace App\Http\Middleware;

use App\Models\Chat;
use App\Models\FinanceRequest;
use App\Models\Notification;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $userLocale = $request->user()?->locale ?? $request->session()->get('locale');
        if (in_array($userLocale, array_keys(config('app.locales')), true)) {
            App::setLocale($userLocale);
        }

        $storedBranding = Setting::branding();
        $logoPath = $storedBranding['logo_path'] ?? null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => App::getLocale(),
            // Client controls submit a locale code. Sending the display
            // metadata objects here made the dashboard select submit JSON
            // instead of `ar`, `en`, or `ku`.
            'locales' => array_keys(config('app.locales')),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // An invitation link is intentionally a one-time flash value:
                // the server only keeps its hash, so it cannot be recovered
                // from the database after the administrator leaves the page.
                'invite_link' => fn () => $request->session()->get('invite_link'),
                // New branch account credentials are intentionally a one-time
                // flash value. Only the encrypted password hash remains in
                // the database after the next navigation.
                'branch_credentials' => fn () => $request->session()->get('branch_credentials'),
            ],
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'username' => $request->user()->username,
                    'phone' => $request->user()->phone,
                    'role' => $request->user()->role,
                    'status' => $request->user()->status,
                    'theme' => $request->user()->theme,
                    'locale' => $request->user()->locale,
                    'shop_name' => $request->user()->shop_name,
                    'address' => $request->user()->address,
                    'vehicle' => $request->user()->vehicle,
                    'phone_verified' => $request->user()->phone_verified_at !== null,
                ] : null,
                'tenant' => $request->user()?->tenant_id ? [
                    'id' => $request->user()->tenant_id,
                    'name' => $request->user()->tenant?->name,
                    'slug' => $request->user()->tenant?->slug,
                    'plan' => $request->user()->tenant?->plan?->slug,
                    'trial_ends_at' => $request->user()->tenant?->trial_ends_at?->toDateString(),
                ] : null,
                'provinces' => $request->user()
                    ? $request->user()->provinces()->orderBy('sort_order')->get(['provinces.id', 'name_ar', 'name_en', 'name_ku'])->map(fn ($province) => [
                        'id' => $province->id,
                        'name_ar' => $province->name_ar,
                        'name_en' => $province->name_en,
                        'name_ku' => $province->name_ku,
                    ])->all()
                    : [],
            ],
            // Keep the bell badge in the mobile shell live on every app
            // screen. Chat unread counts are intentionally separate.
            'notificationUnread' => function () use ($request): int {
                $user = $request->user();

                if (! $user || ($user->role !== 'merchant' && ! $user->isCourierRole())) {
                    return 0;
                }

                return Notification::query()
                    ->where(function ($query) use ($user) {
                        $query->where('user_id', $user->id)
                            ->orWhere(function ($tenant) use ($user) {
                                $tenant->whereNull('user_id')->where('tenant_id', $user->tenant_id);
                            });
                    })
                    ->whereNull('read_at')
                    ->count();
            },
            // Dashboard navigation needs real operational badges rather than
            // static decoration. They are only evaluated for an admin on the
            // administrative host and never expose another user's messages.
            'adminBadges' => function () use ($request): array {
                $user = $request->user();

                if (! $user || ! $user->isAdmin()) {
                    return [];
                }

                return [
                    'finance' => FinanceRequest::withoutGlobalScopes()->where('status', FinanceRequest::PENDING)->count(),
                    'notifications' => Notification::withoutGlobalScopes()->whereNull('read_at')->count(),
                    'chat' => Chat::withoutGlobalScopes()->whereNotNull('last_at')->whereNull('admin_read_at')->count(),
                ];
            },
            'translations' => app()->bound('translations') ? app('translations') : [],
            'branding' => [
                'name' => $storedBranding['name'],
                'tagline' => $storedBranding['tagline'],
                'logo_url' => is_string($logoPath) && $logoPath !== ''
                    ? Storage::disk('public')->url($logoPath)
                    : asset('logo.png'),
                'has_custom_logo' => is_string($logoPath) && $logoPath !== '',
            ],
        ];
    }
}
