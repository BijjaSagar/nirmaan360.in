<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** A 403 on the web. The API reports it as a 422 with errors.plan. */
class PlanLimitReachedException extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct(__('Your plan project limit has been reached. Upgrade to add more.'));
    }
}
