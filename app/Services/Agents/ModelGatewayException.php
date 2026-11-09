<?php

namespace App\Services\Agents;

use RuntimeException;

/** Messages here are shown on the run page, so they never carry the key or the provider's raw reply. */
class ModelGatewayException extends RuntimeException
{
    public const NOT_CONNECTED = 'not_connected';

    public const TIMEOUT = 'timeout';

    public const REJECTED_KEY = 'rejected_key';

    public const RATE_LIMITED = 'rate_limited';

    public const BAD_REQUEST = 'bad_request';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function forStatus(int $status): self
    {
        return match (true) {
            in_array($status, [401, 403], true) => new self(self::REJECTED_KEY, 'The AI service rejected the saved API key.'),
            $status === 429 => new self(self::RATE_LIMITED, 'The AI service is busy. Please try again shortly.'),
            $status >= 400 && $status < 500 => new self(self::BAD_REQUEST, 'The AI service could not accept this request (HTTP '.$status.').'),
            default => new self(self::UNAVAILABLE, 'The AI service is unavailable right now (HTTP '.$status.').'),
        };
    }
}
