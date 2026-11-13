<?php

namespace App\Services\DocumentReading;

use App\Services\Agents\ModelGatewayException;
use App\Services\Agents\ModelResponse;

/**
 * How one call to the reader ended, before anything is saved or charged.
 *
 * READ     the answer has the right shape and the kind accepted it.
 * FAILED   the page cannot be used from this call. $reason is the page's fail reason.
 * AGAIN    the provider was busy or down. $reason is the gateway's reason; trying later may work.
 * BLOCKED  the provider refuses every call for now (key, billing, spending limit, no key). $reason is the gateway's reason.
 */
final readonly class PageOutcome
{
    public const READ = 'read';

    public const FAILED = 'failed';

    public const AGAIN = 'again';

    public const BLOCKED = 'blocked';

    public const INVALID_OUTPUT = 'invalid_output';

    public const CUT_OFF = 'cut_off';

    public const DECLINED = 'declined';

    public const TIMEOUT = 'timeout';

    public const UNAVAILABLE = 'unavailable';

    public const WORKER_LOST = 'worker_lost';

    public const REJECTED = 'rejected';

    public const INTERNAL_ERROR = 'internal_error';

    /**
     * @param  array<string, mixed>|null  $output  The accepted answer, when READ.
     * @param  array{input:int,output:int,cache_read:int,cache_write:int}|null  $usage  What the provider counted. Null when it reported nothing.
     * @param  bool  $sent  Whether the page left the server. False when the call was never made.
     * @param  ?string  $detail  A short note for the page row: a refusal category, where the shape was wrong. Never a value from the page.
     * @param  ?string  $providerMessage  The provider's own words about a rejected call. For the platform admin only.
     */
    public function __construct(
        public string $result,
        public ?string $reason = null,
        public ?array $output = null,
        public ?array $usage = null,
        public bool $sent = true,
        public ?string $stopReason = null,
        public ?string $messageId = null,
        public ?string $requestId = null,
        public int $latencyMs = 0,
        public ?int $retryAfter = null,
        public ?string $detail = null,
        public ?string $providerMessage = null,
    ) {}

    /** @param  array<string, mixed>  $output */
    public static function read(array $output, ModelResponse $response): self
    {
        return new self(self::READ, null, $output, $response->usage, true, $response->stopReason, $response->id, $response->requestId, $response->latencyMs);
    }

    /** The model answered, but the answer cannot be used. Its tokens were spent all the same. */
    public static function answeredBadly(string $reason, ModelResponse $response, ?string $detail = null): self
    {
        return new self(self::FAILED, $reason, null, $response->usage, true, $response->stopReason, $response->id, $response->requestId, $response->latencyMs, detail: $detail);
    }

    /** Our own code failed. Nothing is known to have been spent unless a response is given. */
    public static function internalError(bool $sent = false, ?self $after = null): self
    {
        return new self(self::FAILED, self::INTERNAL_ERROR, null, $after?->usage, $after?->sent ?? $sent, $after?->stopReason, $after?->messageId, $after?->requestId, $after?->latencyMs ?? 0);
    }

    public static function fromFailure(ModelGatewayException $exception, int $latencyMs): self
    {
        $common = ['requestId' => $exception->requestId, 'latencyMs' => $latencyMs];

        return match ($exception->reason) {
            ModelGatewayException::TIMEOUT => new self(self::FAILED, self::TIMEOUT, ...$common),
            ModelGatewayException::RATE_LIMITED, ModelGatewayException::UNAVAILABLE => new self(self::AGAIN, $exception->reason, ...$common, retryAfter: $exception->retryAfter, detail: $exception->errorType),
            ModelGatewayException::SPEND_LIMIT, ModelGatewayException::BILLING, ModelGatewayException::REJECTED_KEY => new self(self::BLOCKED, $exception->reason, ...$common),
            ModelGatewayException::NOT_CONNECTED => new self(self::BLOCKED, $exception->reason, sent: false),
            // The provider would not take the request as it is: too large, or malformed. Sending it again changes nothing.
            default => new self(self::FAILED, self::REJECTED, ...$common,
                detail: trim(($exception->errorType ?? 'refused').' '.($exception->status ?? '')),
                providerMessage: $exception->providerMessage),
        };
    }

    public function failedByBehaviour(): bool
    {
        return $this->result === self::FAILED && in_array($this->reason, [self::INVALID_OUTPUT, self::CUT_OFF, self::DECLINED], true);
    }
}
