<?php

namespace App\Providers;

use App\Models\Notification;
use App\Observers\NotificationPushObserver;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Do not cache this dictionary as a singleton. The locale can change
        // after authentication (or during an Inertia visit), and a singleton
        // would leave some components in the previous language.
        $this->app->bind('translations', function () {
            $locale = App::getLocale();
            $fallback = config('app.fallback_locale');

            $strings = [];

            // Load the fallback first, then let the requested locale replace
            // every translated key it provides. The inverse order silently
            // made Arabic/Kurdish screens retain English fallback labels.
            foreach (array_unique([$fallback, $locale]) as $candidate) {
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
        // Keep indexed string columns compatible with the hosting MySQL version.
        Schema::defaultStringLength(191);

        // HTTPS is terminated by the web server/reverse proxy.  Forcing a
        // redirect again inside Laravel can create an endless redirect when
        // PHP receives the proxy's internal HTTP request.  Apache/cPanel is
        // therefore the HTTPS gate; Laravel only generates canonical HTTPS
        // URLs for either product host.
        if ($this->app->environment(['production', 'staging'])) {
            URL::forceScheme('https');
        }

        // The observer keeps browser push aligned with every committed inbox
        // record, without relying on a queue daemon on shared hosting.
        Notification::observe(NotificationPushObserver::class);
    }
}
