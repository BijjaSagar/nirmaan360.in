<?php

namespace App\Services\DocumentReading;

use App\Models\AiTextCall;
use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Services\Agents\ModelResponse;
use App\Services\DocumentReading\Contracts\ChecksTextAnswer;
use App\Services\DocumentReading\Contracts\TextCallHandler;
use LogicException;
use Throwable;

/**
 * One attempt at a text call and the judgement of its answer. It saves nothing and charges
 * nothing, so a company's call and the platform admin's dry run go through the very same steps:
 * the handler's request, the check of the body, the single attempt, the shape check and the
 * handler's own look at the answer.
 */
class TextCall
{
    public function __construct(private ModelGateway $gateway) {}

    /**
     * @param  AiTextCall  $call  The row the attempt is for. It may be unsaved, as long as it names its purpose and what it is about.
     * @param  array{model?:string, effort?:string, max_tokens?:int, timeout?:int}  $overrides  For a dry run that tries other figures. A company's call passes none.
     *
     * @throws LogicException When the body the handler built may not be sent. Nothing has left the server then.
     * @throws NothingToSend When the handler has nothing to ask any more.
     */
    public function run(TextCallHandler $handler, AiTextCall $call, array $overrides = []): TextOutcome
    {
        $body = $this->body($handler, $call, $overrides);
        $chars = TextRequest::chars($body);
        $timeout = (int) ($overrides['timeout'] ?? TextRequest::figures((string) $call->purpose)['timeout']);
        $started = now();

        try {
            $response = $this->gateway->sendOnce($body, $timeout);
        } catch (ModelGatewayException $exception) {
            return TextOutcome::fromFailure($exception, (int) $started->diffInMilliseconds(now()), $chars);
        }

        // The request was sent and paid for. Whatever goes wrong in looking at the answer, that much is kept.
        try {
            return $this->judge($handler, $call, $body, $response, $chars);
        } catch (Throwable $exception) {
            report($exception);

            return TextOutcome::answeredBadly(TextOutcome::INTERNAL_ERROR, $response, $chars);
        }
    }

    /**
     * The body as it would be sent, after the checks. Also used to size and price a call before it is made.
     *
     * @param  array{model?:string, effort?:string, max_tokens?:int, timeout?:int}  $overrides
     * @return array<string, mixed>
     */
    public function body(TextCallHandler $handler, AiTextCall $call, array $overrides = []): array
    {
        $body = $handler->request($call);

        if (isset($overrides['model'])) {
            $body['model'] = (string) $overrides['model'];
        }

        if (isset($overrides['max_tokens'])) {
            $body['max_tokens'] = (int) $overrides['max_tokens'];
        }

        if (isset($overrides['effort']) && is_array($body['output_config'] ?? null)) {
            $body['output_config']['effort'] = (string) $overrides['effort'];
        }

        if (($problems = TextRequest::problems($body, (string) $call->purpose)) !== []) {
            throw new LogicException("The handler of the text call \"{$call->purpose}\" built a request that may not be sent: ".implode('; ', $problems).'.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array{message:int, all:int}  $chars
     */
    private function judge(TextCallHandler $handler, AiTextCall $call, array $body, ModelResponse $response, array $chars): TextOutcome
    {
        if ($response->stopReason === 'refusal' || ($response->stopDetails['type'] ?? null) === 'refusal') {
            $category = $response->stopDetails['category'] ?? null;

            return TextOutcome::answeredBadly(TextOutcome::DECLINED, $response, $chars, is_string($category) ? $category : null);
        }

        if ($response->stopReason === 'max_tokens') {
            return TextOutcome::answeredBadly(TextOutcome::CUT_OFF, $response, $chars);
        }

        if ($response->stopReason !== 'end_turn') {
            return TextOutcome::answeredBadly(TextOutcome::INTERNAL_ERROR, $response, $chars, 'stop: '.substr((string) $response->stopReason, 0, 40));
        }

        $schema = $body['output_config']['format']['schema'];
        $decoded = json_decode($this->firstText($response), true);

        if (! is_array($decoded)) {
            return TextOutcome::answeredBadly(TextOutcome::INVALID_OUTPUT, $response, $chars, 'not a JSON object');
        }

        // The lines name places in the form, never what was found there.
        if (($errors = ShapeCheck::errors($decoded, $schema)) !== []) {
            return TextOutcome::answeredBadly(TextOutcome::INVALID_OUTPUT, $response, $chars, implode('; ', array_slice($errors, 0, 3)));
        }

        $output = ShapeCheck::lowered($decoded, $schema);

        if (! $handler instanceof ChecksTextAnswer) {
            return TextOutcome::done($output, $response, $chars);
        }

        $refusals = self::codes($handler->refused($call, $output));

        return $refusals === [] ? TextOutcome::done($output, $response, $chars) : TextOutcome::refused($refusals, $response, $chars);
    }

    /**
     * What a handler refused, held to codes: it is stored on the call's row, where no word of an
     * answer may stand. Anything that is not a code is replaced by a neutral one, so a refusal is
     * never lost and never carries text.
     *
     * @return array<string, list<string>>
     */
    public static function codes(mixed $refusals): array
    {
        if (! is_array($refusals) || $refusals === []) {
            return [];
        }

        $code = fn (mixed $value, string $otherwise) => is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,63}$/', $value) === 1 ? $value : $otherwise;
        $clean = [];

        foreach (array_slice($refusals, 0, 40, true) as $part => $rules) {
            $rules = array_map(fn ($rule) => $code($rule, 'refused'), array_slice(array_values((array) $rules), 0, 12));
            $clean[$code(is_int($part) ? "part{$part}" : $part, 'answer')] = array_values(array_unique($rules === [] ? ['refused'] : $rules));
        }

        return $clean;
    }

    /** A model that thinks puts its thinking first. The answer is the first block of text. */
    private function firstText(ModelResponse $response): string
    {
        foreach ($response->content as $block) {
            if (($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }
}
