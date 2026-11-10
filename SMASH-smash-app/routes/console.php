<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('smash:login-url {user=1} {--to=/dashboard}', function () {
    if (! app()->isLocal()) {
        $this->error('smash:login-url is only available when APP_ENV=local.');

        return 1;
    }

    $to = $this->option('to');

    $this->line(URL::temporarySignedRoute('dev.login', now()->addMinutes(10), [
        'user' => (int) $this->argument('user'),
        'to' => is_string($to) && $to !== '' ? $to : '/dashboard',
    ]));

    return 0;
})->purpose('Print a short-lived signed login URL for a user (local development only)');
