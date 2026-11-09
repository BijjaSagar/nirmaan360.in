<?php

namespace App\Services\Agents;

use App\Support\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
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
}
