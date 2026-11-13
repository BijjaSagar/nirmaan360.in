<?php

namespace App\Exceptions;

use App\Support\PlanWording;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A 403 on the web. The API reports it as a 422 with errors.plan.
 * The message is worded for whoever asked: the API's never says upgrade (see PlanWording).
 */
class PlanLimitReachedException extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct(PlanWording::projectLimitReached());
    }
}
