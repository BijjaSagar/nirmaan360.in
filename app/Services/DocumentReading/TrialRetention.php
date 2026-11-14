<?php

namespace App\Services\DocumentReading;

use App\Services\DocumentReading\Contracts\Retains;

/**
 * A test document uploaded for a dry run, and what the model returned for it, stay on the server
 * for 24 hours and no longer. The command that tends document reads runs this once a minute.
 */
class TrialRetention implements Retains
{
    public function __construct(private ReaderTrials $trials) {}

    public function purgeOld(): int
    {
        return $this->trials->purgeDue();
    }
}
