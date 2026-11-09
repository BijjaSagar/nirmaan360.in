<?php

namespace App\Providers;

use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\DynamicTranslator;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformSettings::class);

        $this->app->extend('translator', function ($translator, $app) {
            $dynamic = new DynamicTranslator($app['translation.loader'], $translator->getLocale());
            $dynamic->setFallback($translator->getFallback());

            return $dynamic;
        });
    }

    public function boot(): void
    {
        $this->app->make(PlatformSettings::class)->applyToConfig();

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Every ability is a module.action permission string from config/buildos.php.
        Gate::before(fn (User $user, string $ability) => $user->hasPermission($ability) ? true : null);

        View::composer('layouts.app', function ($view) {
            $user = Auth::user();

            $view->with('pendingApprovals', Approval::where('status', 'IN_REVIEW')->get()
                ->filter(fn ($approval) => app(ApprovalService::class)->canAct($approval, $user))
                ->count());
            $view->with('unreadNotifications', $user->unreadNotifications()->latest()->take(8)->get());
        });
    }
}
