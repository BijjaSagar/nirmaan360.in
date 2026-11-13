<?php

namespace App\Services\DocumentReading\Contracts;

/**
 * For a text call handler the platform admin can try on pasted material, such as a transcript or
 * a list of facts, with another model or other figures. A handler normally builds its request from
 * a company's record; a dry run has no record and may touch no company, so the handler is asked
 * for a twin that works from the pasted text alone.
 *
 * A purpose is offered on the admin's dry-run form only when its handler implements this.
 */
interface TriesPastedText
{
    /**
     * A handler that builds the same request as a company's call would, with the same instructions
     * and the same form, from the pasted text instead of a record, and that applies the same
     * checks to the answer. Its request() gets a call that was never saved and has no company:
     * it reads $call->purpose, $call->model, $call->attempts and $call->correction and nothing
     * from the database. Its done() and failed() are never called.
     *
     * @param  string  $pasted  What the admin pasted, as it was typed. TextRequest::body takes personal
     *                          details out of it for every purpose that redacts.
     */
    public function onPasted(string $pasted): TextCallHandler;
}
