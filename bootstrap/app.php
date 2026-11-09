<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\OfflinePlanGate;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        // OfflinePlanGate: a company on the offline plan never syncs. See config/modules/offline_plan.php.
        $middleware->web(append: [SetLocale::class, OfflinePlanGate::class]);
        $middleware->api(append: [OfflinePlanGate::class]);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'feature' => RequireFeature::class,
            'locale' => SetLocale::class,
        ]);
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
