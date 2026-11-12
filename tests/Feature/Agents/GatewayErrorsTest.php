<?php

namespace Tests\Feature\Agents;

use App\Services\Agents\ModelGatewayException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

/** How a failed reply from the provider is read. Nothing here touches the network or the database. */
class GatewayErrorsTest extends TestCase
{
    /** The body printed on the provider's rate-limits page for a reached monthly cap. It arrives with HTTP 429 and no retry-after. */
    public const SPEND_CAP = [
        'type' => 'error',
        'error' => [
            'type' => 'rate_limit_error',
            'message' => "You have reached your API usage limits: your organization has crossed its monthly API usage threshold, set based on your organization's API tier. You will regain access on 2026-09-01 at 00:00 UTC.",
            'details' => ['error_code' => 'enforced_spend_limit_reached'],
        ],
        'request_id' => 'req_018EeWyXxfu5pfWkrYcMdjWG',
    ];

    /** Built from that page's description of a limit the account holder set: HTTP 400, this error type, these opening words. */
    public const OWN_LIMIT = [
        'type' => 'error',
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'You have reached your specified API usage limits. You will regain access on 2026-12-01 at 00:00 UTC.',
        ],
        'request_id' => 'req_test_own_limit',
    ];

    /** Built from that page's description of a ramp limit: HTTP 429, this code, a retry-after header. */
    public const RAMP_LIMIT = [
        'type' => 'error',
        'error' => [
            'type' => 'rate_limit_error',
            'message' => 'Usage of this model grew too quickly. Hold your traffic steady and raise it slowly.',
            'details' => ['error_code' => 'ramp_limit_exceeded'],
        ],
        'request_id' => 'req_test_ramp',
    ];

    private function read(int $status, array|string $body = [], array $headers = [], ?string $secret = null): ModelGatewayException
    {
        return ModelGatewayException::fromResponse(
            new Response(new PsrResponse($status, $headers, is_array($body) ? json_encode($body) : $body)),
            $secret,
        );
    }

    private function error(string $type, string $message = 'Something went wrong.'): array
    {
        return ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]];
    }

    public function test_a_reached_monthly_cap_is_a_spend_limit_and_not_a_busy_service(): void
    {
        $exception = $this->read(429, self::SPEND_CAP);

        $this->assertSame(ModelGatewayException::SPEND_LIMIT, $exception->reason);
        $this->assertSame(429, $exception->status);
        $this->assertSame('rate_limit_error', $exception->errorType);
        $this->assertSame('enforced_spend_limit_reached', $exception->errorCode);
        $this->assertSame('req_018EeWyXxfu5pfWkrYcMdjWG', $exception->requestId);
        $this->assertNull($exception->retryAfter);
        $this->assertStringStartsWith('You have reached your API usage limits', $exception->providerMessage);
        $this->assertStringNotContainsString('usage threshold', $exception->getMessage());
    }

    public function test_the_cap_code_decides_whatever_the_status(): void
    {
        $this->assertSame(ModelGatewayException::SPEND_LIMIT, $this->read(400, self::SPEND_CAP)->reason);
        $this->assertSame(ModelGatewayException::SPEND_LIMIT, $this->read(403, self::SPEND_CAP)->reason);
    }

    public function test_a_limit_the_account_holder_set_is_a_spend_limit(): void
    {
        $exception = $this->read(400, self::OWN_LIMIT);

        $this->assertSame(ModelGatewayException::SPEND_LIMIT, $exception->reason);
        $this->assertSame(400, $exception->status);
        $this->assertSame('invalid_request_error', $exception->errorType);
        $this->assertNull($exception->errorCode);
        $this->assertSame('req_test_own_limit', $exception->requestId);

        $workspace = $this->error('invalid_request_error', 'You have reached your specified workspace API usage limits. You will regain access on 2026-12-01 at 00:00 UTC.');
        $this->assertSame(ModelGatewayException::SPEND_LIMIT, $this->read(400, $workspace)->reason);
    }

    public function test_only_the_opening_words_of_a_400_make_it_a_spend_limit(): void
    {
        $mentions = $this->error('invalid_request_error', 'messages.0.content.1.text: the page says You have reached your specified API usage limits and the spend limit is near.');

        $this->assertSame(ModelGatewayException::BAD_REQUEST, $this->read(400, $mentions)->reason);
    }

    public function test_the_same_opening_words_with_another_status_are_not_a_spend_limit(): void
    {
        $exception = $this->read(429, $this->error('rate_limit_error', self::OWN_LIMIT['error']['message']), ['retry-after' => '40']);

        $this->assertSame(ModelGatewayException::RATE_LIMITED, $exception->reason);
        $this->assertSame(40, $exception->retryAfter);
    }

    public function test_a_ramp_limit_is_a_busy_service_with_a_wait(): void
    {
        $exception = $this->read(429, self::RAMP_LIMIT, ['retry-after' => '23']);

        $this->assertSame(ModelGatewayException::RATE_LIMITED, $exception->reason);
        $this->assertSame('ramp_limit_exceeded', $exception->errorCode);
        $this->assertSame(23, $exception->retryAfter);
        $this->assertSame('The AI service is busy. Please try again shortly.', $exception->getMessage());
    }

    public function test_the_wait_is_whole_seconds_or_nothing(): void
    {
        $busy = $this->error('rate_limit_error');

        $this->assertNull($this->read(429, $busy)->retryAfter);
        $this->assertSame(0, $this->read(429, $busy, ['retry-after' => '0'])->retryAfter);
        $this->assertSame(12, $this->read(429, $busy, ['retry-after' => ' 12 '])->retryAfter);
        $this->assertSame(600, $this->read(429, $busy, ['retry-after' => '600'])->retryAfter);
        $this->assertNull($this->read(429, $busy, ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'])->retryAfter);
        $this->assertNull($this->read(429, $busy, ['retry-after' => '-5'])->retryAfter);
        $this->assertNull($this->read(429, $busy, ['retry-after' => '1.5'])->retryAfter);
        $this->assertNull($this->read(429, $busy, ['retry-after' => '99999999999999999999'])->retryAfter);
    }

    public function test_each_status_gets_its_reason(): void
    {
        $expected = [
            400 => ModelGatewayException::BAD_REQUEST,
            401 => ModelGatewayException::REJECTED_KEY,
            402 => ModelGatewayException::BILLING,
            403 => ModelGatewayException::REJECTED_KEY,
            404 => ModelGatewayException::BAD_REQUEST,
            409 => ModelGatewayException::BAD_REQUEST,
            413 => ModelGatewayException::TOO_LARGE,
            422 => ModelGatewayException::BAD_REQUEST,
            429 => ModelGatewayException::RATE_LIMITED,
            500 => ModelGatewayException::UNAVAILABLE,
            502 => ModelGatewayException::UNAVAILABLE,
            503 => ModelGatewayException::UNAVAILABLE,
            504 => ModelGatewayException::UNAVAILABLE,
            529 => ModelGatewayException::UNAVAILABLE,
        ];

        foreach ($expected as $status => $reason) {
            $exception = $this->read($status, $this->error('some_error'));

            $this->assertSame($reason, $exception->reason, (string) $status);
            $this->assertSame($status, $exception->status);
        }
    }

    public function test_the_providers_error_type_is_kept_when_the_service_is_down(): void
    {
        foreach ([[500, 'api_error'], [500, 'timeout_error'], [504, 'timeout_error'], [529, 'overloaded_error']] as [$status, $type]) {
            $exception = $this->read($status, $this->error($type));

            $this->assertSame(ModelGatewayException::UNAVAILABLE, $exception->reason);
            $this->assertSame($type, $exception->errorType);
            $this->assertSame('The AI service is unavailable right now (HTTP '.$status.').', $exception->getMessage());
        }
    }

    public function test_a_reply_that_is_not_the_providers_json_is_still_read(): void
    {
        $html = $this->read(413, '<html><body><h1>413 Request Entity Too Large</h1></body></html>', ['request-id' => 'req_test_from_header']);

        $this->assertSame(ModelGatewayException::TOO_LARGE, $html->reason);
        $this->assertNull($html->errorType);
        $this->assertNull($html->errorCode);
        $this->assertNull($html->providerMessage);
        $this->assertSame('req_test_from_header', $html->requestId);

        $empty = $this->read(502, '');
        $this->assertSame(ModelGatewayException::UNAVAILABLE, $empty->reason);
        $this->assertNull($empty->requestId);

        $loose = $this->read(529, ['error' => 'overloaded']);
        $this->assertSame(ModelGatewayException::UNAVAILABLE, $loose->reason);
        $this->assertNull($loose->providerMessage);

        $odd = $this->read(400, ['error' => ['type' => ['x'], 'message' => ['y'], 'details' => 'z'], 'request_id' => 77]);
        $this->assertSame(ModelGatewayException::BAD_REQUEST, $odd->reason);
        $this->assertNull($odd->errorType);
        $this->assertNull($odd->errorCode);
        $this->assertNull($odd->requestId);
        $this->assertNull($odd->providerMessage);
    }

    public function test_the_request_id_in_the_body_wins_over_the_header(): void
    {
        $body = [...$this->error('api_error'), 'request_id' => 'req_test_body'];

        $this->assertSame('req_test_body', $this->read(500, $body, ['request-id' => 'req_test_header'])->requestId);
        $this->assertSame('req_test_header', $this->read(500, $this->error('api_error'), ['request-id' => 'req_test_header'])->requestId);
        $this->assertNull($this->read(500, $this->error('api_error'))->requestId);
    }

    public function test_the_providers_words_are_cut_flattened_and_kept_apart(): void
    {
        $marathi = str_repeat('पान वाचता आले नाही. ', 40);
        $exception = $this->read(400, $this->error('invalid_request_error', "line one\nline two\t\0end ".$marathi));

        $this->assertSame(300, mb_strlen($exception->providerMessage));
        $this->assertStringStartsWith('line one line two end पान वाचता', $exception->providerMessage);
        $this->assertSame('The AI service could not accept this request (HTTP 400).', $exception->getMessage());
    }

    public function test_a_key_repeated_by_the_provider_is_blanked(): void
    {
        $body = $this->error('authentication_error', 'invalid x-api-key: not-a-real-key-123');

        $exception = $this->read(401, $body, secret: 'not-a-real-key-123');

        $this->assertSame(ModelGatewayException::REJECTED_KEY, $exception->reason);
        $this->assertSame('invalid x-api-key: [key]', $exception->providerMessage);
        $this->assertSame('The AI service rejected the saved API key.', $exception->getMessage());
    }

    public function test_the_new_reasons_have_their_own_plain_messages(): void
    {
        $this->assertSame('The AI service has reached its usage limit and is paused for now.', $this->read(429, self::SPEND_CAP)->getMessage());
        $this->assertSame('The AI service is paused until its billing is put right.', $this->read(402, $this->error('billing_error'))->getMessage());
        $this->assertSame('This request is too large for the AI service.', $this->read(413, $this->error('request_too_large'))->getMessage());
    }

    public function test_the_older_way_of_raising_is_unchanged(): void
    {
        $plain = new ModelGatewayException(ModelGatewayException::TIMEOUT, 'The AI service did not answer in time.');

        $this->assertSame(ModelGatewayException::TIMEOUT, $plain->reason);
        $this->assertSame('The AI service did not answer in time.', $plain->getMessage());

        foreach (['status', 'errorType', 'errorCode', 'requestId', 'retryAfter', 'providerMessage'] as $field) {
            $this->assertNull($plain->{$field}, $field);
        }

        $byStatus = [
            401 => [ModelGatewayException::REJECTED_KEY, 'The AI service rejected the saved API key.'],
            403 => [ModelGatewayException::REJECTED_KEY, 'The AI service rejected the saved API key.'],
            429 => [ModelGatewayException::RATE_LIMITED, 'The AI service is busy. Please try again shortly.'],
            402 => [ModelGatewayException::BAD_REQUEST, 'The AI service could not accept this request (HTTP 402).'],
            413 => [ModelGatewayException::BAD_REQUEST, 'The AI service could not accept this request (HTTP 413).'],
            529 => [ModelGatewayException::UNAVAILABLE, 'The AI service is unavailable right now (HTTP 529).'],
        ];

        foreach ($byStatus as $status => [$reason, $message]) {
            $exception = ModelGatewayException::forStatus($status);

            $this->assertSame($reason, $exception->reason);
            $this->assertSame($message, $exception->getMessage());
            $this->assertNull($exception->status);
        }
    }
}
