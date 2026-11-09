<?php

namespace App\Http\Middleware;

use App\Services\OfflinePlan\OfflinePlanPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company on the offline plan never syncs. On the API everything but signing in, the licence endpoints
 * and the profile answers 403 with the code "offline_plan"; on the web only account, billing and devices
 * stay open and anything else lands on the devices page with its upgrade prompt.
 *
 * Runs with the web and api groups, before route middleware, so the person is read from the guard the
 * route itself would use: the session on the web, the Sanctum token on signed-in API routes.
 */
class OfflinePlanGate
{
    public function __construct(private OfflinePlanPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            return $this->api($request, $route, $next);
        }

        $organization = $request->user()?->organization;

        if ($organization === null || ! $this->policy->isOffline($organization) || $this->policy->webAllowed($route)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethodSafe()) {
            abort(403, $this->policy->refusal($organization)['message']);
        }

        return redirect()->route('offline.devices.index');
    }

    private function api(Request $request, Route $route, Closure $next): Response
    {
        if (! $this->policy->signsInWithSanctum($route)) {
            return $next($request);
        }

        $organization = $request->user('sanctum')?->organization;

        if ($organization === null || ! $this->policy->isOffline($organization) || $this->policy->apiAllowed($request)) {
            return $next($request);
        }

        return response()->json($this->policy->refusal($organization), 403);
    }
}
