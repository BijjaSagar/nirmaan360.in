<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\PublicApi\ApiKeyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a request in with a company API key, for the public API. Use as AuthenticateApiKey::class.':boq:read'.
 *
 * There is no signed-in user on these requests, so the organisation scope on models does not apply.
 * Controllers read the company from the request attribute "api_organization_id" and filter on it themselves.
 */
class AuthenticateApiKey
{
    private const FAILURES_PER_MINUTE = 30;

    public function __construct(private ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $failures = 'api-key-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failures, self::FAILURES_PER_MINUTE)) {
            return $this->error(429, 'too_many_requests', 'Too many requests with an invalid API key. Try again in a minute.', ['Retry-After' => RateLimiter::availableIn($failures)]);
        }

        $secret = $request->bearerToken() ?: (string) $request->header('X-Api-Key');
        $key = $secret !== '' ? $this->keys->find($secret) : null;

        if (! $key || ! $key->isActive()) {
            RateLimiter::hit($failures, 60);

            return $this->error(401, 'invalid_api_key', 'The API key is missing, wrong, revoked or expired. Send it as "Authorization: Bearer <key>".');
        }

        $organization = Organization::find($key->organization_id);

        if (! $organization || ! $this->keys->enabledFor($organization)) {
            return $this->error(403, 'plan_not_included', 'Your company plan does not include the public API.');
        }

        if (! $key->allows($scope)) {
            return $this->error(403, 'insufficient_scope', "This key does not have the {$scope} scope.");
        }

        $bucket = 'api-key:'.$key->id;
        $limit = (int) $key->rate_limit_per_minute;

        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            return $this->error(429, 'rate_limited', "Rate limit of {$limit} requests a minute reached.", [
                'Retry-After' => RateLimiter::availableIn($bucket),
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($bucket, 60);

        // One write a minute at most, so a busy key does not turn every read into a write.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_organization_id', $key->organization_id);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($bucket, $limit));

        return $response;
    }

    private function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }
}
