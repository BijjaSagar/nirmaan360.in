<?php

namespace App\Services\Reports;

use RuntimeException;

/** PdfTable passed the deadline it was given before the table was put together. */
final class PdfTableTimedOut extends RuntimeException {}
