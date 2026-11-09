<?php

namespace App\Services\OfflinePlan;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** A licence or sync request the offline plan does not allow, with a code the app can act on. */
class OfflinePlanRefused extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 403,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->reason, 'message' => $this->getMessage(), ...$this->extra];
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json($this->toArray(), $this->status);
        }

        return back()->withErrors(['offline_plan' => $this->getMessage()]);
    }
}
