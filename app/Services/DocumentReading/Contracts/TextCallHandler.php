<?php

namespace App\Services\DocumentReading\Contracts;

use App\Models\AiTextCall;

/**
 * What one kind of text call asks the model and what it does with the answer. A feature module
 * writes the class and lists it under "text_calls" in its own config/modules file, by purpose:
 *
 *     'text_calls' => ['rate_match' => RankCall::class],
 *
 * A purpose that several features share is listed per record the call is about, by that record's
 * morph name (the model's class name unless the application maps it):
 *
 *     'text_calls' => ['wording' => [SiteLetter::class => LetterWording::class]],
 *
 * The purpose's model, output limit and time limit are in config modules.document_reading.purposes.
 *
 * The runner does everything that is the same for every text call: the limits, the claim, the one
 * attempt, the charge and the retries. A handler says what to send and what to keep.
 *
 * The call's row holds neither the request nor the answer. So request() builds the request from
 * the records each time it is asked, finding the record through $call->subject_type and
 * $call->subject_id, and done() stores what it wants to keep in the feature's own table.
 *
 * Nothing here runs with a signed-in person, so every query a handler makes names the company
 * ($call->organization_id).
 */
interface TextCallHandler
{
    /**
     * The Messages body for this call, built with TextRequest::body so that it carries no tools,
     * no thinking or sampling setting and nothing that names the company, the person or the project.
     *
     * It is asked once before the call is created (the call is then not saved yet and has no id)
     * and once for every attempt. After an answer that the handler refused, $call->correction holds
     * what was refused, by code, and the request should say so to the model.
     *
     * Throw NothingToSend when the record is gone or there is nothing to ask any more.
     *
     * @return array<string, mixed>
     */
    public function request(AiTextCall $call): array;

    /**
     * The model answered in the form the request asked for. Keep what is wanted of it.
     *
     * Runs inside the transaction that marks the call as done and charges it, so database work
     * only: no file, no HTTP call. If it throws, nothing of the answer is kept, nothing is charged
     * and the call ends as failed.
     *
     * Model text is untrusted: cut it to field lengths, check every id against what was sent, and
     * never use it as HTML, a path, a link or a figure.
     *
     * @param  array<string, mixed>  $output  The decoded answer. It has the shape of the request's schema.
     */
    public function done(AiTextCall $call, array $output): void;

    /**
     * The call ended without an answer that can be used. Nothing was charged. Put the feature's own
     * fallback in place: the code template, the order by score, a plain message.
     *
     * Runs inside the transaction that ends the call: database work only. If it throws, the call
     * still ends; the error is reported and the feature must cope with a call that says "failed".
     *
     * @param  string  $reason  The call's fail_reason: "rejected" when the handler's own checks refused the
     *                          answer twice, otherwise a reason such as timeout, unavailable, cut_off,
     *                          declined, invalid_output, not_connected, company_limit or worker_lost.
     */
    public function failed(AiTextCall $call, string $reason): void;
}
