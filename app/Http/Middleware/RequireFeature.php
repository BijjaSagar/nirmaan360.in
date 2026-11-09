<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(
            $request->user()->organization->allowsFeature($feature),
            403,
            __('Your plan does not include this module. Upgrade to continue.')
        );

        return $next($request);
    }
}
