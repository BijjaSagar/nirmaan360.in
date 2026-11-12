<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The person asked to delete their account in one way, but the company changed since they looked
 * (for example a second owner left), so it would now happen in the other way. They must look again.
 * The API answers 409 with the code below.
 */
class AccountDeletionChanged extends ConflictHttpException
{
    public const CODE = 'deletion_mode_changed';

    public function __construct()
    {
        parent::__construct(__('Something changed in your company. Check what will happen, then try again.'));
    }
}
