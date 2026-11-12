<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A contractor login reaches the contractor portal and nothing else. Every other signed-in route,
 * web or API, turns it away before any record is looked up: a page request is sent to the portal,
 * anything else is refused. Public pages carry no company data, so they stay open.
 *
 * Registered on the web and api groups by ContractorPortalServiceProvider, and placed right after
 * authentication in the middleware order so it runs ahead of route-model binding.
 */
class ContractorPortalGate
{
    /**
     * Route names a contractor still needs outside the portal: signing out, changing language and deleting their account.
     * Named one by one, so a later account.* page is not opened to contractors by accident. account.cancel reaches
     * the service, which refuses anyone but an owner, so a contractor gets the same 403 it would get here.
     */
    private const ALLOWED_NAMES = ['contractor-portal.*', 'logout', 'locale', 'account.delete', 'account.destroy', 'account.cancel'];

    /** The field app's own calls: who am I, sign out, delete my account, and the portal endpoints. */
    private const ALLOWED_URIS = ['api/v1/me', 'api/v1/auth/logout', 'api/v1/auth/account/deletion', 'api/v1/contractor-portal', 'api/v1/contractor-portal/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isContractor() || $this->allowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403, __('This is a contractor login. It reaches the contractor portal only.'));
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()->route('contractor-portal.home');
        }

        abort(403, __('This is a contractor login. It reaches the contractor portal only.'));
    }

    private function allowed(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return true;
        }

        $name = $route->getName();

        if (($name !== null && Str::is(self::ALLOWED_NAMES, $name)) || $request->is(...self::ALLOWED_URIS)) {
            return true;
        }

        return ! $this->requiresSignIn($route);
    }

    private function requiresSignIn(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))) {
                return true;
            }
        }

        return false;
    }
}
