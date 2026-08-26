<?php

namespace App\Http\Middleware;

use Inertia\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $userLocale = $request->user()?->locale ?? $request->session()->get('locale');
        if (in_array($userLocale, array_keys(config('app.locales')), true)) {
            App::setLocale($userLocale);
        }

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
            'translations' => app()->bound('translations') ? app('translations') : [],
        ];
    }
}
