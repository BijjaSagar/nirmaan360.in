<?php

namespace App\Services\Agents;

use RuntimeException;

/** A run could not start, for a reason the user can read. */
class AgentRunBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
