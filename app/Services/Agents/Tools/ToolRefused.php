<?php

namespace App\Services\Agents\Tools;

use RuntimeException;

/** The call is not allowed at all. The runner records and audits it as a refusal. */
class ToolRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
