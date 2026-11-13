<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Services\DocumentReading\Contracts\Retains;

/** Stands in for a module's own clean-up, such as blanking old voice note text. */
class FakeRetention implements Retains
{
    public static int $runs = 0;

    public function purgeOld(): int
    {
        self::$runs++;

        return 3;
    }
}
