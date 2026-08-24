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
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => App::getLocale(),
            'locales' => array_values(config('app.locales')),
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
                ] : null,
                'tenant' => $request->user()?->tenant_id ? [
                    'id' => $request->user()->tenant_id,
                    'name' => $request->user()->tenant?->name,
                    'slug' => $request->user()->tenant?->slug,
                    'plan' => $request->user()->tenant?->plan?->slug,
                    'trial_ends_at' => $request->user()->tenant?->trial_ends_at?->toDateString(),
                ] : null,
            ],
            'translations' => app()->bound('translations') ? app('translations') : [],
        ];
    }
}
