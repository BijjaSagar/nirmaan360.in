<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Approved records cannot change. Corrections are made with reversal entries. */
class ImmutableRecordException extends ConflictHttpException
{
    public function __construct(string $message = 'This record is approved and cannot be changed. Create a reversal entry instead.')
    {
        parent::__construct($message);
    }
}
