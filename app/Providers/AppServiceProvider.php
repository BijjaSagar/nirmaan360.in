<?php

namespace App\Providers;

use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
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
