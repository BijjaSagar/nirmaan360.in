<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\SetLocale;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [SetLocale::class]);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'feature' => RequireFeature::class,
            'locale' => SetLocale::class,
        ]);
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A form that fails validation is flashed back to the session. Saved secrets must never rest there.
        $exceptions->dontFlash(PlatformSettings::SECRETS);
        // On a web page, too many tries goes back to the form with a plain message instead of a bare error page.
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return back()->withErrors(['throttle' => __('Too many tries in a short time. Wait a minute and try again.')]);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
