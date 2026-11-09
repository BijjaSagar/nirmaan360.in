<?php

namespace App\Providers;

use App\Http\Middleware\ContractorPortalGate;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\ServiceProvider;

/**
 * Puts the contractor portal's gate on every web and API request. The gate sits right after
 * authentication in the middleware order, so a contractor login is turned away from any route
 * outside the portal before route-model binding looks anything up.
 */
class ContractorPortalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        if (! $kernel instanceof Kernel) {
            return;
        }

        $kernel->appendMiddlewareToGroup('web', ContractorPortalGate::class);
        $kernel->appendMiddlewareToGroup('api', ContractorPortalGate::class);
        $kernel->addToMiddlewarePriorityAfter(AuthenticatesRequests::class, ContractorPortalGate::class);
    }
}
