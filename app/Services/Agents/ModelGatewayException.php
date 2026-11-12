<?php

namespace App\Services\Agents;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Messages here are shown on the run page, so they never carry the key or the provider's raw reply.
 * The provider's own words are kept apart in $providerMessage, which is for the platform admin and never for a company.
 */
class ModelGatewayException extends RuntimeException
{
    public const NOT_CONNECTED = 'not_connected';

    public const TIMEOUT = 'timeout';

    public const REJECTED_KEY = 'rejected_key';

    public const RATE_LIMITED = 'rate_limited';

    public const BAD_REQUEST = 'bad_request';

    public const UNAVAILABLE = 'unavailable';

    public const BILLING = 'billing';

    public const TOO_LARGE = 'too_large';

    /** The provider account has used up its month, or a limit the account holder set. Trying again does not help. */
    public const SPEND_LIMIT = 'spend_limit';

    private const SPEND_CAP_CODE = 'enforced_spend_limit_reached';

    private const OWN_LIMIT_OPENING = 'You have reached your specified';

    /**
     * @param  int|null  $retryAfter  Seconds the provider asked for in its retry-after header. Null when it sent none.
     * @param  string|null  $providerMessage  At most 300 characters of the provider's error text.
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorType = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $providerMessage = null,
    ) {
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

    /**
     * Reads a failed reply in full, for callers that decide by themselves whether and when to try again.
     * The two spend limits are told apart from a rate limit by the provider's own code and opening words, never by guessing from the text.
     *
     * @param  string|null  $secret  The key that was sent. It is blanked out if the provider repeats it.
     */
    public static function fromResponse(Response $response, ?string $secret = null): self
    {
        $status = $response->status();
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $text = is_string($error['message'] ?? null) ? $error['message'] : '';
        $code = is_array($error['details'] ?? null) ? self::clip($error['details']['error_code'] ?? null, 100) : null;

        $reason = match (true) {
            $code === self::SPEND_CAP_CODE => self::SPEND_LIMIT,
            $status === 400 && str_starts_with($text, self::OWN_LIMIT_OPENING) => self::SPEND_LIMIT,
            in_array($status, [401, 403], true) => self::REJECTED_KEY,
            $status === 402 => self::BILLING,
            $status === 413 => self::TOO_LARGE,
            $status === 429 => self::RATE_LIMITED,
            $status >= 400 && $status < 500 => self::BAD_REQUEST,
            default => self::UNAVAILABLE,
        };

        $message = match ($reason) {
            self::SPEND_LIMIT => 'The AI service has reached its usage limit and is paused for now.',
            self::BILLING => 'The AI service is paused until its billing is put right.',
            self::TOO_LARGE => 'This request is too large for the AI service.',
            default => self::forStatus($status)->getMessage(),
        };

        if (filled($secret)) {
            $text = str_replace($secret, '[key]', $text);
        }

        return new self(
            $reason,
            $message,
            $status,
            self::clip($error['type'] ?? null, 100),
            $code,
            self::clip(is_array($body) ? ($body['request_id'] ?? null) : null, 100) ?? self::clip($response->header('request-id'), 100),
            self::seconds($response->header('retry-after')),
            self::clip($text, 300),
        );
    }

    /** A whole number of seconds, or null. A date in this header is treated as no answer, so the caller uses its own wait. */
    private static function seconds(string $header): ?int
    {
        $header = trim($header);

        return ctype_digit($header) && strlen($header) < 10 ? (int) $header : null;
    }

    /** One line of plain text no longer than the limit, safe to store. Null for anything that is not text. */
    private static function clip(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
