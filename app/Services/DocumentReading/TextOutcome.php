<?php

namespace App\Services\DocumentReading;

use App\Services\Agents\ModelGatewayException;
use App\Services\Agents\ModelResponse;

/**
 * How one attempt at a text call ended, before anything is saved or charged.
 *
 * DONE     the answer has the right shape and the handler has nothing against it.
 * REFUSED  the answer has the right shape, but the handler's own checks refused it. $refusals says what, by code.
 * FAILED   no usable answer from this attempt. $reason is the call's fail reason.
 * AGAIN    the provider was busy or down. $reason is the gateway's reason; trying later may work.
 * BLOCKED  the provider refuses every call for now (key, billing, spending limit, no key). $reason is the gateway's reason.
 */
final readonly class TextOutcome
{
    public const DONE = 'done';

    public const REFUSED = 'refused';

    public const FAILED = 'failed';

    public const AGAIN = 'again';

    public const BLOCKED = 'blocked';

    /** The answer was not JSON in the agreed form. */
    public const INVALID_OUTPUT = 'invalid_output';

    /** The answer ran into the output limit. */
    public const CUT_OFF = 'cut_off';

    /** The model declined to answer. */
    public const DECLINED = 'declined';

    public const TIMEOUT = 'timeout';

    public const UNAVAILABLE = 'unavailable';

    /** The provider would not take the request as it is. Sending it again changes nothing. */
    public const BAD_REQUEST = 'bad_request';

    public const INTERNAL_ERROR = 'internal_error';

    /**
     * @param  array<string, mixed>|null  $output  The answer, when DONE.
     * @param  array{input:int,output:int,cache_read:int,cache_write:int}|null  $usage  What the provider counted. Null when it reported nothing.
     * @param  bool  $sent  Whether the request left the server. False when the call was never made.
     * @param  ?string  $detail  A short note for a log: a refusal category, where the shape was wrong. Never a word of the answer.
     * @param  ?string  $providerMessage  The provider's own words about a request it would not take. For the platform admin only.
     * @param  array<string, list<string>>  $refusals  What the handler refused, when REFUSED: part of the answer => rule codes.
     * @param  int  $messageChars  Characters of the message that was built, 0 when none was.
     * @param  int  $allChars  Characters of everything that was built: instructions, form and message.
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
        public array $refusals = [],
        public int $messageChars = 0,
        public int $allChars = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  array{message:int, all:int}  $chars
     */
    public static function done(array $output, ModelResponse $response, array $chars): self
    {
        return new self(self::DONE, null, $output, ...self::answered($response, $chars));
    }

    /**
     * @param  array<string, list<string>>  $refusals
     * @param  array{message:int, all:int}  $chars
     */
    public static function refused(array $refusals, ModelResponse $response, array $chars): self
    {
        return new self(self::REFUSED, null, null, ...self::answered($response, $chars), refusals: $refusals);
    }

    /**
     * The model answered, but the answer cannot be used. Its tokens were spent all the same.
     *
     * @param  array{message:int, all:int}  $chars
     */
    public static function answeredBadly(string $reason, ModelResponse $response, array $chars, ?string $detail = null): self
    {
        return new self(self::FAILED, $reason, null, ...self::answered($response, $chars), detail: $detail);
    }

    /** Our own code failed, or there was nothing to send. Nothing is known to have been spent unless an earlier outcome is given. */
    public static function notSent(string $reason = self::INTERNAL_ERROR): self
    {
        return new self(self::FAILED, $reason, sent: false);
    }

    /**
     * An answer came, but keeping it failed or the handler no longer wanted it. The call was made
     * and paid for all the same, so what is known of its cost is kept.
     */
    public static function unkept(self $after, string $reason = self::INTERNAL_ERROR): self
    {
        return new self(
            self::FAILED, $reason, null, $after->usage, $after->sent, $after->stopReason, $after->messageId, $after->requestId,
            $after->latencyMs, messageChars: $after->messageChars, allChars: $after->allChars,
        );
    }

    /** @param  array{message:int, all:int}  $chars */
    public static function fromFailure(ModelGatewayException $exception, int $latencyMs, array $chars): self
    {
        $common = ['requestId' => $exception->requestId, 'latencyMs' => $latencyMs, 'messageChars' => $chars['message'], 'allChars' => $chars['all']];

        return match ($exception->reason) {
            ModelGatewayException::TIMEOUT => new self(self::FAILED, self::TIMEOUT, ...$common),
            ModelGatewayException::RATE_LIMITED, ModelGatewayException::UNAVAILABLE => new self(self::AGAIN, $exception->reason, ...$common, retryAfter: $exception->retryAfter, detail: $exception->errorType),
            ModelGatewayException::SPEND_LIMIT, ModelGatewayException::BILLING, ModelGatewayException::REJECTED_KEY => new self(self::BLOCKED, $exception->reason, ...$common),
            ModelGatewayException::NOT_CONNECTED => new self(self::BLOCKED, $exception->reason, sent: false, messageChars: $chars['message'], allChars: $chars['all']),
            // Too large, or malformed. The provider's words are kept apart from the company's rows.
            default => new self(self::FAILED, self::BAD_REQUEST, ...$common,
                detail: trim(($exception->errorType ?? 'refused').' '.($exception->status ?? '')),
                providerMessage: $exception->providerMessage),
        };
    }

    /**
     * @param  array{message:int, all:int}  $chars
     * @return array<string, mixed>
     */
    private static function answered(ModelResponse $response, array $chars): array
    {
        return [
            'usage' => $response->usage, 'sent' => true, 'stopReason' => $response->stopReason, 'messageId' => $response->id,
            'requestId' => $response->requestId, 'latencyMs' => $response->latencyMs, 'messageChars' => $chars['message'], 'allChars' => $chars['all'],
        ];
    }
}
