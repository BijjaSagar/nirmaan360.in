<?php

namespace Tests\Feature\Agents;

use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Services\Agents\ModelResponse;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The single-attempt call and the token count that sit beside send(). No test here reaches the real service. */
class GatewayOnceTest extends AgentTestCase
{
    private const BODY = [
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 4000,
        'system' => [['type' => 'text', 'text' => 'Copy the page.']],
        'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Page 1 of 2.']]]],
        'output_config' => ['effort' => 'low'],
    ];

    /** @var (Closure(Request, array): mixed)|null */
    private ?Closure $next = null;

    private function gateway(): ModelGateway
    {
        return app(ModelGateway::class);
    }

    /** Http::fake keeps the first stub it was given, so a test that needs several replies in turn swaps this one instead. */
    private function nextReply(mixed $reply): void
    {
        if ($this->next === null) {
            Http::fake(['api.anthropic.com/*' => fn (Request $request, array $options) => ($this->next)($request, $options)]);
        }

        $this->next = $reply instanceof Closure ? $reply : fn () => $reply;
    }

    private function failure(callable $call): ModelGatewayException
    {
        try {
            $call();
        } catch (ModelGatewayException $exception) {
            return $exception;
        }

        $this->fail('The gateway should have raised.');
    }

    public function test_send_once_makes_no_call_without_a_key(): void
    {
        Http::fake();

        $exception = $this->failure(fn () => $this->gateway()->sendOnce(self::BODY, 120));

        $this->assertSame(ModelGatewayException::NOT_CONNECTED, $exception->reason);
        $this->assertNull($exception->status);
        Http::assertNothingSent();
    }

    public function test_send_once_posts_the_body_as_given_with_the_key_and_version(): void
    {
        $this->connect();
        $sent = null;

        Http::fake(['api.anthropic.com/*' => function (Request $request) use (&$sent) {
            $sent = $request;

            return Http::response(
                [...$this->message([['type' => 'text', 'text' => '{"rows":[]}']], 'end_turn', 5200, 310), 'model' => 'claude-sonnet-5-5'],
                200,
                ['request-id' => 'req_test_0001'],
            );
        }]);

        $response = $this->gateway()->sendOnce(self::BODY, 120);

        $this->assertSame('https://api.anthropic.com/v1/messages', $sent->url());
        $this->assertSame('POST', $sent->method());
        $this->assertTrue($sent->hasHeader('x-api-key', 'test-key-value'));
        $this->assertTrue($sent->hasHeader('anthropic-version', '2026-10-21'));
        $this->assertFalse($sent->hasHeader('anthropic-beta'));
        $this->assertSame(self::BODY, $sent->data());

        $this->assertSame('{"rows":[]}', $response->text());
        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame('claude-sonnet-5-5', $response->model);
        $this->assertSame('req_test_0001', $response->requestId);
        $this->assertNull($response->stopDetails);
        $this->assertSame(['input' => 5200, 'output' => 310, 'cache_read' => 0, 'cache_write' => 0], $response->usage);
        Http::assertSentCount(1);
    }

    public function test_send_once_uses_the_given_time_limit_and_keeps_the_line_alive(): void
    {
        $this->connect();
        $options = null;

        Http::fake(['api.anthropic.com/*' => function (Request $request, array $given) use (&$options) {
            $options = $given;

            return Http::response($this->message([['type' => 'text', 'text' => 'ok']], 'end_turn'));
        }]);

        $this->gateway()->sendOnce(self::BODY, 240);

        $this->assertSame(240, $options['timeout']);
        $this->assertSame(10, $options['connect_timeout']);
        $this->assertSame(1, $options['curl'][CURLOPT_TCP_KEEPALIVE]);
        $this->assertSame(30, $options['curl'][CURLOPT_TCP_KEEPIDLE]);
        $this->assertSame(15, $options['curl'][CURLOPT_TCP_KEEPINTVL]);
    }

    public function test_send_once_never_tries_a_second_time(): void
    {
        $this->connect();
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
            ->push($this->message([['type' => 'text', 'text' => 'ok']], 'end_turn'));

        $exception = $this->failure(fn () => $this->gateway()->sendOnce(self::BODY, 120));

        $this->assertSame(ModelGatewayException::UNAVAILABLE, $exception->reason);
        $this->assertSame(529, $exception->status);
        $this->assertSame('overloaded_error', $exception->errorType);
        Http::assertSentCount(1);
    }

    public function test_send_once_reports_a_dropped_line_as_a_timeout(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::failedConnection()]);

        $exception = $this->failure(fn () => $this->gateway()->sendOnce(self::BODY, 120));

        $this->assertSame(ModelGatewayException::TIMEOUT, $exception->reason);
        $this->assertNull($exception->status);
        Http::assertSentCount(1);
    }

    public function test_send_once_carries_the_details_of_a_refusal(): void
    {
        $this->connect();
        $details = ['type' => 'refusal', 'category' => null, 'explanation' => 'Declined.'];

        Http::fake(['api.anthropic.com/*' => Http::response([...$this->message([], 'refusal'), 'stop_details' => $details])]);

        $response = $this->gateway()->sendOnce(self::BODY, 120);

        $this->assertSame('refusal', $response->stopReason);
        $this->assertSame($details, $response->stopDetails);
        $this->assertSame('', $response->text());
        $this->assertNull($response->requestId, 'No request-id header was sent back.');
    }

    public function test_send_once_keeps_usage_to_the_four_priced_kinds(): void
    {
        $this->connect();
        $reply = $this->message([['type' => 'text', 'text' => 'ok']], 'end_turn');
        $reply['usage'] = [
            'input_tokens' => 2095,
            'output_tokens' => 503,
            'cache_read_input_tokens' => 2051,
            'cache_creation_input_tokens' => 1200,
            'cache_creation' => ['ephemeral_1h_input_tokens' => 0, 'ephemeral_5m_input_tokens' => 1200],
            'output_tokens_details' => ['thinking_tokens' => 40],
            'service_tier' => 'standard',
        ];

        Http::fake(['api.anthropic.com/*' => Http::response($reply)]);

        $this->assertSame(
            ['input' => 2095, 'output' => 503, 'cache_read' => 2051, 'cache_write' => 1200],
            $this->gateway()->sendOnce(self::BODY, 120)->usage,
        );
    }

    public function test_send_once_explains_a_failure_without_showing_the_providers_words_or_the_key(): void
    {
        $this->connect();
        $long = 'messages.0.content.0: bad key test-key-value '.str_repeat('x', 400);

        Http::fake(['api.anthropic.com/*' => Http::response(
            ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => $long], 'request_id' => 'req_test_0002'],
            400,
        )]);

        $exception = $this->failure(fn () => $this->gateway()->sendOnce(self::BODY, 120));

        $this->assertSame(ModelGatewayException::BAD_REQUEST, $exception->reason);
        $this->assertSame('req_test_0002', $exception->requestId);
        $this->assertStringNotContainsString('messages.0', $exception->getMessage());
        $this->assertStringNotContainsString('test-key-value', $exception->getMessage());
        $this->assertSame(300, mb_strlen($exception->providerMessage));
        $this->assertStringStartsWith('messages.0.content.0: bad key [key] xxx', $exception->providerMessage);
    }

    public function test_send_once_tells_a_reached_spend_cap_from_a_busy_service(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(GatewayErrorsTest::SPEND_CAP, 429)]);

        $exception = $this->failure(fn () => $this->gateway()->sendOnce(self::BODY, 120));

        $this->assertSame(ModelGatewayException::SPEND_LIMIT, $exception->reason);
        Http::assertSentCount(1);
    }

    public function test_count_tokens_asks_the_counting_address_and_leaves_out_what_it_does_not_take(): void
    {
        $this->connect();
        $sent = null;
        $options = null;

        Http::fake(['api.anthropic.com/*' => function (Request $request, array $given) use (&$sent, &$options) {
            $sent = $request;
            $options = $given;

            return Http::response(['input_tokens' => 2095]);
        }]);

        $count = $this->gateway()->countTokens([...self::BODY, 'metadata' => ['user_id' => 'u1'], 'stream' => false]);

        $this->assertSame(2095, $count);
        $this->assertSame('https://api.anthropic.com/v1/messages/count_tokens', $sent->url());
        $this->assertTrue($sent->hasHeader('x-api-key', 'test-key-value'));
        $this->assertTrue($sent->hasHeader('anthropic-version', '2026-10-21'));
        $this->assertEqualsCanonicalizing(['model', 'system', 'messages', 'output_config'], array_keys($sent->data()));
        $this->assertSame(self::BODY['messages'], $sent->data()['messages']);
        $this->assertSame(self::BODY['output_config'], $sent->data()['output_config']);
        $this->assertSame(20, $options['timeout']);
        Http::assertSentCount(1);
    }

    public function test_count_tokens_makes_no_call_without_a_key(): void
    {
        Http::fake();

        $exception = $this->failure(fn () => $this->gateway()->countTokens(self::BODY));

        $this->assertSame(ModelGatewayException::NOT_CONNECTED, $exception->reason);
        Http::assertNothingSent();
    }

    public function test_count_tokens_fails_in_the_same_words_as_a_call(): void
    {
        $this->connect();
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Slow down.']], 429, ['retry-after' => '7'])
            ->push(['input_tokens' => 10]);

        $exception = $this->failure(fn () => $this->gateway()->countTokens(self::BODY));

        $this->assertSame(ModelGatewayException::RATE_LIMITED, $exception->reason);
        $this->assertSame(7, $exception->retryAfter);
        Http::assertSentCount(1);
    }

    public function test_count_tokens_reports_a_dropped_line_as_a_timeout(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::failedConnection()]);

        $this->assertSame(ModelGatewayException::TIMEOUT, $this->failure(fn () => $this->gateway()->countTokens(self::BODY))->reason);
    }

    public function test_count_tokens_refuses_an_answer_without_a_count(): void
    {
        $this->connect();

        foreach ([[], ['input_tokens' => null], ['input_tokens' => 'many'], ['input_tokens' => -1]] as $body) {
            $this->nextReply(Http::response($body, 200, ['request-id' => 'req_test_0003']));

            $exception = $this->failure(fn () => $this->gateway()->countTokens(self::BODY));

            $this->assertSame(ModelGatewayException::UNAVAILABLE, $exception->reason, json_encode($body));
            $this->assertSame('req_test_0003', $exception->requestId);
        }
    }

    public function test_send_still_reads_failures_by_status_alone(): void
    {
        $this->connect();

        foreach ([402 => ModelGatewayException::BAD_REQUEST, 413 => ModelGatewayException::BAD_REQUEST, 401 => ModelGatewayException::REJECTED_KEY] as $status => $reason) {
            $this->nextReply(Http::response(GatewayErrorsTest::SPEND_CAP, $status, ['request-id' => 'req_test_0004']));

            $exception = $this->failure(fn () => $this->gateway()->send(self::BODY));

            $this->assertSame($reason, $exception->reason, (string) $status);
            $this->assertNull($exception->status);
            $this->assertNull($exception->requestId);
            $this->assertNull($exception->providerMessage);
        }
    }

    public function test_send_fills_the_new_fields_it_can_and_leaves_the_request_id_empty(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(
            [...$this->message([['type' => 'text', 'text' => 'hi']], 'end_turn'), 'model' => 'claude-opus-5-5'],
            200,
            ['request-id' => 'req_test_0005'],
        )]);

        $response = $this->gateway()->send(self::BODY);

        $this->assertInstanceOf(ModelResponse::class, $response);
        $this->assertSame('claude-opus-5-5', $response->model);
        $this->assertNull($response->requestId);
        $this->assertSame(['input', 'output', 'cache_read', 'cache_write'], array_keys($response->usage));
    }
}
