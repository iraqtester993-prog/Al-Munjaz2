<?php

namespace App\Providers;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Session\Middleware\StartSession;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('translations', function () {
            $locale = App::getLocale();
            $fallback = config('app.fallback_locale');

            $strings = [];

            foreach ([$locale, $fallback] as $candidate) {
                $path = lang_path($candidate.'.json');
                if (! File::exists($path)) {
                    continue;
                }

                $strings = array_replace($strings, json_decode(File::get($path), true) ?: []);
            }

            return $strings;
        });
    }

    public function boot(): void
    {
        View::composer('app', function ($view) {
            $view->with('translations', app('translations'));
        });
    }
}
