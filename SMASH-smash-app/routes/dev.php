<?php

/*
|--------------------------------------------------------------------------
| Local-only developer routes
|--------------------------------------------------------------------------
|
| Only registered when APP_ENV=local (see routes/web.php). The login route
| requires a valid signature, generated with `php artisan smash:login-url`,
| and is used for screenshotting authenticated pages during development.
|
*/

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('_dev/login/{user}', function (Request $request, User $user) {
    Auth::login($user);
    $request->session()->regenerate();

    $to = (string) $request->query('to', '/dashboard');

    // Only allow same-site relative redirects.
    if (! str_starts_with($to, '/') || str_starts_with($to, '//')) {
        $to = '/dashboard';
    }

    return redirect($to);
})->middleware('signed')->whereNumber('user')->name('dev.login');

Route::livewire('_dev/styleguide', 'pages::styleguide')->name('dev.styleguide');
