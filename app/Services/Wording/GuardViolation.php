<?php

namespace App\Services\Wording;

/**
 * A figure would have appeared in a document without coming from a fact. The document is not produced.
 * The message names the rule and the slot only, never the text, so it is safe to log.
 */
final class GuardViolation extends WordingException
{
    public function __construct(public readonly string $rule, public readonly string $slot, public readonly string $found = '')
    {
        parent::__construct("Wording guard '{$rule}' stopped slot '{$slot}'.");
    }
}
