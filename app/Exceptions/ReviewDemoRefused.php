<?php

namespace App\Exceptions;

use RuntimeException;

/** The review demo command would not run, or stopped. The message is printed in the console and holds no secret. */
class ReviewDemoRefused extends RuntimeException {}
