<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// cPanel triggers `schedule:run` once per minute. This task then archives
// completed work just after midnight in the application's Baghdad timezone.
Schedule::command('orders:archive-completed')
    ->dailyAt('00:05')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30);
