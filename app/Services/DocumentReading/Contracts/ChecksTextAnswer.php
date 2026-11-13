<?php

namespace App\Services\DocumentReading\Contracts;

use App\Models\AiTextCall;

/**
 * For a text call handler that may refuse a whole answer, as the wording guards do when a model
 * sentence states a figure. A handler that only drops the parts it cannot use (an unknown
 * reference, a note that fails a check) does that in done() and does not need this.
 *
 * A refused answer is not charged. The first time, the request is sent once more with
 * $call->correction filled in, so the model can be told what was wrong. If that answer is refused
 * too, the call ends as "rejected", failed() is told "rejected", and the refusal counts toward the
 * company's daily limit of unusable answers.
 */
interface ChecksTextAnswer
{
    /**
     * What is wrong with an answer that already has the right shape. Empty when it may be used.
     *
     * No database writes: this runs before anything is saved, and for the platform admin's dry run
     * on a call that was never saved.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, list<string>> A part of the answer (a slot id, a field) => the rules it broke.
     *                                     Codes only, made of letters, digits, "_", "-", "." and ":".
     *                                     They are stored on the call's row, so never a word of the answer.
     */
    public function refused(AiTextCall $call, array $output): array;
}
