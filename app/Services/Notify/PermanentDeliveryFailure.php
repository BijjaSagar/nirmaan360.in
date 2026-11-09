<?php

namespace App\Services\Notify;

use RuntimeException;

/** The provider refused this message and sending it again would fail the same way. */
class PermanentDeliveryFailure extends RuntimeException {}
