<?php

namespace App\Services\Agents\Tools;

use RuntimeException;

/** The call could not be done. The message goes back to the model so it can correct itself or ask. */
class ToolFailure extends RuntimeException {}
