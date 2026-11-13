<?php

namespace App\Http\Middleware;

use App\Support\PlanWording;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A module the company's plan does not include answers 403. The API's wording never says upgrade: see PlanWording. */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(
            $request->user()->organization->allowsFeature($feature),
            403,
            PlanWording::moduleNotIncluded()
        );

        return $next($request);
    }
}
