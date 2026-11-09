<?php

namespace App\Services\Sso;

use RuntimeException;

/** A sign-in that cannot go ahead. The message is safe to show on the sign-in page. */
class SsoException extends RuntimeException {}
