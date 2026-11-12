<?php

namespace App\Services\Agents;

use App\Support\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/** The one place that calls the Anthropic Messages API. With no saved key it reports "not connected". */
class ModelGateway
{
    public const DEFAULT_MODEL = 'claude-sonnet-5-5';

    public const HARD_MODEL = 'claude-opus-5-5';

    public const CHEAP_MODEL = 'claude-haiku-5-5';

    private const BASE_URL = 'https://api.anthropic.com/v1';

    private const API_VERSION = '2026-10-21';

    /** Failures that are usually gone a second later. Timeouts are not retried: the job has a time limit. */
    private const RETRYABLE = [429, 500, 502, 503, 529];

    /** The parts of a Messages body that the token counting address accepts. max_tokens is not one of them. */
    private const COUNTABLE = ['model', 'messages', 'system', 'tools', 'tool_choice', 'thinking', 'output_config', 'cache_control', 'speed'];

    private const COUNT_TIMEOUT = 20;

    public function __construct(private PlatformSettings $settings) {}

    public function isConnected(): bool
    {
        return filled($this->apiKey());
    }

    private function apiKey(): ?string
    {
        return $this->settings->get('anthropic_api_key');
    }

    private function client(string $key, int $timeout): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => self::API_VERSION,
        ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout($timeout)
            ->retry(2, 1000, fn (Throwable $e) => $e instanceof RequestException && in_array($e->response->status(), self::RETRYABLE, true), throw: false);
    }

    /** Asks Anthropic whether the saved key works, without spending any tokens. */
    public function keyWorks(): bool
    {
        $key = $this->apiKey();

        if (blank($key)) {
            return false;
        }

        return $this->client($key, 15)->get(self::BASE_URL.'/models', ['limit' => 1])->successful();
    }

    /**
     * @param  array<string,mixed>  $request  A Messages API request body.
     *
     * @throws ModelGatewayException
     */
    public function send(array $request, ?int $timeoutSeconds = null): ModelResponse
    {
        $key = $this->apiKey();

        if (blank($key)) {
            throw new ModelGatewayException(ModelGatewayException::NOT_CONNECTED, 'AI agents are not connected.');
        }

        $started = now();

        try {
            $response = $this->client($key, $timeoutSeconds ?? (int) config('modules.agents.http_timeout'))
                ->post(self::BASE_URL.'/messages', $request);
        } catch (ConnectionException) {
            throw new ModelGatewayException(ModelGatewayException::TIMEOUT, 'The AI service did not answer in time.');
        }

        if (! $response->successful()) {
            throw ModelGatewayException::forStatus($response->status());
        }

        return ModelResponse::fromArray((array) $response->json(), (int) $started->diffInMilliseconds(now()));
    }

    /**
     * One attempt and no second try, for work that queues its own retries and needs to know exactly why a call failed.
     *
     * @param  array<string,mixed>  $request  A Messages API request body.
     *
     * @throws ModelGatewayException
     */
    public function sendOnce(array $request, int $timeoutSeconds): ModelResponse
    {
        $started = now();
        $response = $this->postOnce('/messages', $request, $timeoutSeconds);

        return ModelResponse::fromArray((array) $response->json(), (int) $started->diffInMilliseconds(now()), $response->header('request-id'));
    }

    /**
     * Asks Anthropic how many input tokens a Messages body would use. It spends no tokens.
     *
     * @param  array<string,mixed>  $request  A Messages API request body. Parts the counting address does not take are left out.
     *
     * @throws ModelGatewayException
     */
    public function countTokens(array $request): int
    {
        $response = $this->postOnce('/messages/count_tokens', Arr::only($request, self::COUNTABLE), self::COUNT_TIMEOUT);
        $tokens = $response->json('input_tokens');

        // A count of nothing would make a quote too low, so an answer without the figure is a failure.
        if (! is_int($tokens) || $tokens < 0) {
            throw new ModelGatewayException(
                ModelGatewayException::UNAVAILABLE,
                'The AI service gave an answer that could not be used.',
                $response->status(),
                requestId: $response->header('request-id') ?: null,
            );
        }

        return $tokens;
    }

    /**
     * @param  array<string,mixed>  $body
     *
     * @throws ModelGatewayException
     */
    private function postOnce(string $path, array $body, int $timeoutSeconds): Response
    {
        $key = $this->apiKey();

        if (blank($key)) {
            throw new ModelGatewayException(ModelGatewayException::NOT_CONNECTED, 'AI agents are not connected.');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => self::API_VERSION,
            ])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout($timeoutSeconds)
                ->withOptions(['curl' => self::keepAlive()])
                ->post(self::BASE_URL.$path, $body);
        } catch (ConnectionException) {
            throw new ModelGatewayException(ModelGatewayException::TIMEOUT, 'The AI service did not answer in time.');
        }

        if (! $response->successful()) {
            throw ModelGatewayException::fromResponse($response, $key);
        }

        return $response;
    }

    /**
     * A long answer leaves the line silent for minutes, and some networks drop a silent line. Keep-alive probes stop that.
     *
     * @return array<int,int>
     */
    private static function keepAlive(): array
    {
        if (! defined('CURLOPT_TCP_KEEPALIVE')) {
            return [];
        }

        return [CURLOPT_TCP_KEEPALIVE => 1, CURLOPT_TCP_KEEPIDLE => 30, CURLOPT_TCP_KEEPINTVL => 15];
    }
}
