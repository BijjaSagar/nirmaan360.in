<?php

namespace App\Services\Wording;

use RuntimeException;

/** Something in a document type, a fact or a template is declared wrongly. A fault in the code, never in what a person typed. */
class WordingException extends RuntimeException {}
