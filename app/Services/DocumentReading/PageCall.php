<?php

namespace App\Services\DocumentReading;

use App\Models\DocumentReadPage;
use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Services\Agents\ModelResponse;
use App\Services\DocumentReading\Contracts\ReadKind;
use LogicException;
use Throwable;

/**
 * One call to the reader and the judgement of its answer. It saves nothing and charges nothing,
 * so a company's read and the platform admin's dry run go through the very same steps: the kind's
 * request, the single attempt, the shape check and the kind's own look at the answer.
 */
class PageCall
{
    public function __construct(private ModelGateway $gateway) {}

    /**
     * @param  DocumentReadPage  $page  The row to read. It may be unsaved, with its documentRead relation set by hand.
     * @param  ?string  $imageBytes  The stored page picture, or null for a run of text pages.
     * @param  ?string  $text  The wrapped text of the run (ReaderRequest::pages), or null for a picture.
     * @param  array{model?:string, effort?:string, max_tokens?:int, timeout?:int}  $overrides  For a dry run that tries other figures. A company's read passes none.
     *
     * @throws LogicException When the body the kind built may not be sent. Nothing has left the server then.
     */
    public function run(ReadKind $kind, DocumentReadPage $page, ?string $imageBytes, ?string $text, array $overrides = []): PageOutcome
    {
        if (($imageBytes === null) === ($text === null)) {
            throw new LogicException('A reader call carries a page picture or page text, one of the two.');
        }

        $figures = ReaderRequest::figures($kind->key(), (string) $page->pass);
        $body = $this->body($kind, $page, $imageBytes, $text, $overrides);
        $timeout = (int) ($overrides['timeout'] ?? $figures['timeout']);
        $started = now();

        try {
            $response = $this->gateway->sendOnce($body, $timeout);
        } catch (ModelGatewayException $exception) {
            return PageOutcome::fromFailure($exception, (int) $started->diffInMilliseconds(now()));
        }

        return $this->judge($kind, $page, $body, $response);
    }

    /**
     * The body as it would be sent, after the checks. Also used to count a kind's prompt.
     *
     * @param  array{model?:string, effort?:string, max_tokens?:int, timeout?:int}  $overrides
     * @return array<string, mixed>
     */
    public function body(ReadKind $kind, DocumentReadPage $page, ?string $imageBytes, ?string $text, array $overrides = []): array
    {
        // The picture is turned into text here, in memory, and lives only as long as this call.
        $body = $kind->request($page, $imageBytes === null ? null : base64_encode($imageBytes), $text);

        if (isset($overrides['model'])) {
            $body['model'] = (string) $overrides['model'];
        }

        if (isset($overrides['max_tokens'])) {
            $body['max_tokens'] = (int) $overrides['max_tokens'];
        }

        if (isset($overrides['effort']) && is_array($body['output_config'] ?? null)) {
            $body['output_config']['effort'] = (string) $overrides['effort'];
        }

        if (($problems = ReaderRequest::problems($body)) !== []) {
            throw new LogicException("The {$kind->key()} reader built a request that may not be sent: ".implode('; ', $problems).'.');
        }

        return $body;
    }

    /** @param  array<string, mixed>  $body */
    private function judge(ReadKind $kind, DocumentReadPage $page, array $body, ModelResponse $response): PageOutcome
    {
        if ($response->stopReason === 'refusal' || ($response->stopDetails['type'] ?? null) === 'refusal') {
            $category = $response->stopDetails['category'] ?? null;

            return PageOutcome::answeredBadly(PageOutcome::DECLINED, $response, is_string($category) ? $category : null);
        }

        if ($response->stopReason === 'max_tokens') {
            return PageOutcome::answeredBadly(PageOutcome::CUT_OFF, $response);
        }

        if ($response->stopReason !== 'end_turn') {
            return PageOutcome::answeredBadly(PageOutcome::INTERNAL_ERROR, $response, 'stop: '.substr((string) $response->stopReason, 0, 40));
        }

        $schema = $body['output_config']['format']['schema'];
        $decoded = json_decode($this->firstText($response), true);

        if (! is_array($decoded)) {
            return PageOutcome::answeredBadly(PageOutcome::INVALID_OUTPUT, $response, 'not a JSON object');
        }

        // The lines name places in the form, never what was found there.
        if (($errors = ShapeCheck::errors($decoded, $schema)) !== []) {
            return PageOutcome::answeredBadly(PageOutcome::INVALID_OUTPUT, $response, implode('; ', array_slice($errors, 0, 3)));
        }

        try {
            $accepted = $kind->accept(ShapeCheck::lowered($decoded, $schema), $page);
        } catch (Throwable $exception) {
            report($exception);

            return PageOutcome::internalError(after: PageOutcome::answeredBadly(PageOutcome::INTERNAL_ERROR, $response));
        }

        return $accepted === null
            ? PageOutcome::answeredBadly(PageOutcome::INVALID_OUTPUT, $response, 'not usable')
            : PageOutcome::read($accepted, $response);
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
